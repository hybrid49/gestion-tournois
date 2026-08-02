<?php

namespace App\Service;

use App\Entity\GameMatch;
use App\Entity\Team;
use App\Entity\Tournament;
use App\Enum\BracketType;
use App\Enum\MatchPhase;
use App\Enum\MatchStatus;
use App\Enum\TournamentStatus;
use Doctrine\ORM\EntityManagerInterface;

class BracketGeneratorService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GroupStageService $groupStageService,
    ) {
    }

    public function generate(Tournament $tournament): Tournament
    {
        if ($tournament->hasGroupStage()) {
            $this->groupStageService->assertGroupStageComplete($tournament);
            $participants = $this->groupStageService->getQualifiers($tournament);
        } else {
            $participants = $tournament->getTeams()->toArray();
            usort($participants, static fn (Team $a, Team $b) => $a->getSeed() <=> $b->getSeed());
        }

        if (count($participants) < 2) {
            throw new \InvalidArgumentException('Il faut au moins 2 participants pour le bracket.');
        }

        foreach ($tournament->getMatches()->toArray() as $match) {
            if ($match->getPhase() !== MatchPhase::Group) {
                $tournament->getMatches()->removeElement($match);
                $this->em->remove($match);
            }
        }
        $this->em->flush();

        if ($tournament->getBracketType() === BracketType::Double) {
            $this->generateDouble($tournament, $participants);
        } else {
            $this->generateSingle($tournament, $participants);
        }

        $tournament->setStatus(TournamentStatus::Bracket);
        $this->em->flush();

        return $tournament;
    }

    /** @param list<Team> $participants */
    private function generateSingle(Tournament $tournament, array $participants): void
    {
        $size = $this->nextPowerOfTwo(count($participants));
        $seeds = $this->seedList($participants, $size);
        $sort = 1000;

        /** @var array<int, array<int, GameMatch>> $rounds */
        $rounds = [];
        $roundCount = (int) log($size, 2);

        for ($r = 1; $r <= $roundCount; ++$r) {
            $rounds[$r] = [];
            $matchCount = (int) ($size / (2 ** $r));
            for ($s = 1; $s <= $matchCount; ++$s) {
                $match = $this->newMatch(
                    $tournament,
                    $r === $roundCount ? MatchPhase::Final : MatchPhase::Winner,
                    $r,
                    $s,
                    ++$sort
                );
                $rounds[$r][$s] = $match;
            }
        }

        for ($s = 1; $s <= $size / 2; ++$s) {
            $home = $seeds[($s - 1) * 2];
            $away = $seeds[($s - 1) * 2 + 1];
            $match = $rounds[1][$s];
            $match->setHomeTeam($home)->setAwayTeam($away);

            if (null === $away && null !== $home) {
                $match->setWinner($home)->setStatus(MatchStatus::Done);
            } elseif (null === $home && null !== $away) {
                $match->setWinner($away)->setStatus(MatchStatus::Done);
            }
        }

        $this->em->flush();

        for ($r = 1; $r < $roundCount; ++$r) {
            foreach ($rounds[$r] as $slot => $match) {
                $next = $rounds[$r + 1][(int) ceil($slot / 2)];
                $match->setNextMatchId($next->getId());
                $match->setNextMatchIsHome($slot % 2 === 1);
                if ($match->getStatus() === MatchStatus::Done && $match->getWinner()) {
                    $this->placeTeam($next, $match->getWinner(), (bool) $match->getNextMatchIsHome());
                }
            }
        }

        $this->em->flush();
    }

    /**
     * Double élimination standard :
     * - WB R1 losers s'affrontent en LB R1 (par paires)
     * - WB R2+ losers tombent en rounds pairs du LB
     * - Winner LB vs Winner WB → grande finale
     *
     * @param list<Team> $participants
     */
    private function generateDouble(Tournament $tournament, array $participants): void
    {
        $size = $this->nextPowerOfTwo(count($participants));
        $seeds = $this->seedList($participants, $size);
        $sort = 2000;
        $wbRounds = (int) log($size, 2);

        /** @var array<int, array<int, GameMatch>> $wb */
        $wb = [];
        for ($r = 1; $r <= $wbRounds; ++$r) {
            $wb[$r] = [];
            $count = (int) ($size / (2 ** $r));
            for ($s = 1; $s <= $count; ++$s) {
                $wb[$r][$s] = $this->newMatch($tournament, MatchPhase::Winner, $r, $s, ++$sort);
            }
        }

        // Cas 2 équipes : WB unique + finale (pas de vrai LB multi-rounds)
        if ($wbRounds === 1) {
            $grandFinal = $this->newMatch($tournament, MatchPhase::Final, 1, 1, ++$sort);
            $wb[1][1]
                ->setHomeTeam($seeds[0])
                ->setAwayTeam($seeds[1])
                ->setNextMatchId(null);

            $this->em->flush();
            $wb[1][1]->setNextMatchId($grandFinal->getId());
            $wb[1][1]->setNextMatchIsHome(true);
            // Le perdant du WB joue aussi la GF (reset) — placé à l'extérieur
            $wb[1][1]->setLoserNextMatchId($grandFinal->getId());
            $wb[1][1]->setLoserNextMatchIsHome(false);
            $this->em->flush();

            return;
        }

        // LB : 2*(wbRounds-1) rounds — ex: 8 teams → 4 rounds [2,2,1,1]
        $lbRoundCount = 2 * ($wbRounds - 1);
        /** @var array<int, array<int, GameMatch>> $lb */
        $lb = [];
        for ($r = 1; $r <= $lbRoundCount; ++$r) {
            $lb[$r] = [];
            $exponent = (int) floor(($r + 1) / 2) + 1;
            $count = max(1, (int) ($size / (2 ** $exponent)));
            for ($s = 1; $s <= $count; ++$s) {
                $lb[$r][$s] = $this->newMatch($tournament, MatchPhase::Loser, $r, $s, ++$sort);
            }
        }

        $grandFinal = $this->newMatch($tournament, MatchPhase::Final, 1, 1, ++$sort);

        for ($s = 1; $s <= $size / 2; ++$s) {
            $home = $seeds[($s - 1) * 2];
            $away = $seeds[($s - 1) * 2 + 1];
            $match = $wb[1][$s];
            $match->setHomeTeam($home)->setAwayTeam($away);
            if (null === $away && null !== $home) {
                $match->setWinner($home)->setStatus(MatchStatus::Done);
            } elseif (null === $home && null !== $away) {
                $match->setWinner($away)->setStatus(MatchStatus::Done);
            }
        }

        $this->em->flush();

        // Chemin vainqueurs WB
        for ($r = 1; $r < $wbRounds; ++$r) {
            foreach ($wb[$r] as $slot => $match) {
                $next = $wb[$r + 1][(int) ceil($slot / 2)];
                $match->setNextMatchId($next->getId());
                $match->setNextMatchIsHome($slot % 2 === 1);
            }
        }
        $wbFinal = $wb[$wbRounds][1];
        $wbFinal->setNextMatchId($grandFinal->getId());
        $wbFinal->setNextMatchIsHome(true);

        // WB R1 losers → LB R1 (appariés home/away)
        foreach ($wb[1] as $slot => $match) {
            $lbSlot = (int) ceil($slot / 2);
            if (!isset($lb[1][$lbSlot])) {
                continue;
            }
            $match->setLoserNextMatchId($lb[1][$lbSlot]->getId());
            $match->setLoserNextMatchIsHome($slot % 2 === 1);
        }

        // WB R2 .. R(n-1) losers → rounds pairs du LB (drop-in en away)
        for ($r = 2; $r < $wbRounds; ++$r) {
            $lbRound = ($r - 1) * 2;
            foreach ($wb[$r] as $slot => $match) {
                if (!isset($lb[$lbRound][$slot])) {
                    continue;
                }
                $match->setLoserNextMatchId($lb[$lbRound][$slot]->getId());
                $match->setLoserNextMatchIsHome(false);
            }
        }

        // Perdant de la WB final → finale LB (away)
        $lbFinal = $lb[$lbRoundCount][1];
        $wbFinal->setLoserNextMatchId($lbFinal->getId());
        $wbFinal->setLoserNextMatchIsHome(false);

        // Progression interne LB
        for ($r = 1; $r < $lbRoundCount; ++$r) {
            if ($r % 2 === 1) {
                // Consolidation → drop : 1:1, vainqueur en home
                foreach ($lb[$r] as $slot => $match) {
                    if (!isset($lb[$r + 1][$slot])) {
                        continue;
                    }
                    $match->setNextMatchId($lb[$r + 1][$slot]->getId());
                    $match->setNextMatchIsHome(true);
                }
            } else {
                // Drop → consolidation : appariement
                foreach ($lb[$r] as $slot => $match) {
                    $nextSlot = (int) ceil($slot / 2);
                    if (!isset($lb[$r + 1][$nextSlot])) {
                        continue;
                    }
                    $match->setNextMatchId($lb[$r + 1][$nextSlot]->getId());
                    $match->setNextMatchIsHome($slot % 2 === 1);
                }
            }
        }

        $lbFinal->setNextMatchId($grandFinal->getId());
        $lbFinal->setNextMatchIsHome(false);

        // Avancer les byes WB R1 (+ éventuels byes LB)
        foreach ($wb[1] as $match) {
            if ($match->getStatus() !== MatchStatus::Done || !$match->getWinner()) {
                continue;
            }
            if ($match->getNextMatchId()) {
                $next = $this->em->find(GameMatch::class, $match->getNextMatchId());
                if ($next) {
                    $this->placeTeam($next, $match->getWinner(), (bool) $match->getNextMatchIsHome());
                }
            }
            // Bye : pas de perdant à envoyer en LB
        }

        $this->em->flush();
        $this->resolvePendingByes($tournament);
        $this->em->flush();
    }

    /**
     * Si tous les matchs alimentant un match sont terminés et qu'une seule équipe
     * est présente → bye automatique (ex: adversaire issu d'un bye WB).
     */
    public function resolvePendingByes(Tournament $tournament): void
    {
        $changed = true;
        $guard = 0;
        while ($changed && $guard < 50) {
            $changed = false;
            ++$guard;
            foreach ($tournament->getMatches() as $match) {
                if ($match->getPhase() === MatchPhase::Group) {
                    continue;
                }
                if ($match->getStatus() === MatchStatus::Done) {
                    continue;
                }

                $home = $match->getHomeTeam();
                $away = $match->getAwayTeam();
                if (($home && $away) || (!$home && !$away)) {
                    continue;
                }

                $feeders = [];
                foreach ($tournament->getMatches() as $candidate) {
                    if ($candidate->getId() === $match->getId()) {
                        continue;
                    }
                    if ($candidate->getNextMatchId() === $match->getId()
                        || $candidate->getLoserNextMatchId() === $match->getId()) {
                        $feeders[] = $candidate;
                    }
                }

                if ([] === $feeders) {
                    continue;
                }

                $allDone = true;
                foreach ($feeders as $feeder) {
                    if ($feeder->getStatus() !== MatchStatus::Done) {
                        $allDone = false;
                        break;
                    }
                }
                if (!$allDone) {
                    continue;
                }

                $lone = $home ?? $away;
                $match->setWinner($lone)->setStatus(MatchStatus::Done);
                $this->propagate($match);
                $changed = true;
            }
        }
    }

    public function propagate(GameMatch $match): void
    {
        $winner = $match->getWinner();
        if (!$winner) {
            return;
        }

        if ($match->getNextMatchId()) {
            $next = $this->em->find(GameMatch::class, $match->getNextMatchId());
            if ($next) {
                $this->placeTeam($next, $winner, (bool) $match->getNextMatchIsHome());
            }
        }

        $home = $match->getHomeTeam();
        $away = $match->getAwayTeam();
        $loser = null;
        if ($home && $away) {
            $loser = $winner->getId() === $home->getId() ? $away : $home;
        }

        if ($loser && $match->getLoserNextMatchId()) {
            $lb = $this->em->find(GameMatch::class, $match->getLoserNextMatchId());
            if ($lb) {
                $this->placeTeam($lb, $loser, (bool) $match->getLoserNextMatchIsHome());
            }
        }
    }

    private function newMatch(
        Tournament $tournament,
        MatchPhase $phase,
        int $round,
        int $slot,
        int $sort,
    ): GameMatch {
        $match = (new GameMatch())
            ->setTournament($tournament)
            ->setPhase($phase)
            ->setRound($round)
            ->setSlot($slot)
            ->setSortOrder($sort)
            ->setStatus(MatchStatus::Pending);
        $tournament->addMatch($match);
        $this->em->persist($match);

        return $match;
    }

    private function placeTeam(GameMatch $match, Team $team, bool $isHome): void
    {
        if ($isHome) {
            $match->setHomeTeam($team);
        } else {
            $match->setAwayTeam($team);
        }
    }

    /** @param list<Team> $participants @return list<?Team> */
    private function seedList(array $participants, int $size): array
    {
        $slots = array_fill(0, $size, null);
        $order = $this->standardSeedOrder($size);
        foreach ($participants as $i => $team) {
            $slots[$order[$i]] = $team;
        }

        return $slots;
    }

    /** @return list<int> */
    private function standardSeedOrder(int $size): array
    {
        $seeds = [1, 2];
        for ($current = 2; $current < $size; $current *= 2) {
            $next = [];
            $sum = $current * 2 + 1;
            foreach ($seeds as $seed) {
                $next[] = $seed;
                $next[] = $sum - $seed;
            }
            $seeds = $next;
        }

        return array_map(static fn (int $s) => $s - 1, $seeds);
    }

    private function nextPowerOfTwo(int $n): int
    {
        $p = 1;
        while ($p < $n) {
            $p *= 2;
        }

        return max(2, $p);
    }
}
