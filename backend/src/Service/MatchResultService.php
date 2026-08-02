<?php

namespace App\Service;

use App\Entity\GameMatch;
use App\Entity\Team;
use App\Entity\Tournament;
use App\Enum\MatchPhase;
use App\Enum\MatchStatus;
use App\Enum\TournamentStatus;
use Doctrine\ORM\EntityManagerInterface;

class MatchResultService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StandingService $standingService,
        private readonly StatsService $statsService,
        private readonly BracketGeneratorService $bracketGeneratorService,
    ) {
    }

    public function setResult(GameMatch $match, Team $winner, ?int $scoreHome = null, ?int $scoreAway = null): GameMatch
    {
        $home = $match->getHomeTeam();
        $away = $match->getAwayTeam();
        if (!$home || !$away) {
            throw new \InvalidArgumentException('Les deux équipes doivent être définies.');
        }
        if ($winner->getId() !== $home->getId() && $winner->getId() !== $away->getId()) {
            throw new \InvalidArgumentException('Le vainqueur doit être l\'une des deux équipes.');
        }

        $alreadyDone = $match->getStatus() === MatchStatus::Done && null !== $match->getWinner();

        if ($alreadyDone) {
            if (!$this->canCorrect($match)) {
                throw new \InvalidArgumentException(
                    'Impossible de corriger ce match : une des équipes a déjà rejoué ensuite.'
                );
            }
            // Annuler l’ancien résultat (classements / stats / placements bracket)
            if ($match->getPhase() === MatchPhase::Group) {
                $this->standingService->revertGroupResult($match);
            }
            $this->statsService->revertMatchResult($match);
            $this->clearPropagation($match);
        }

        $match->setWinner($winner);
        $match->setScoreHome($scoreHome);
        $match->setScoreAway($scoreAway);
        $match->setStatus(MatchStatus::Done);

        if ($match->getPhase() === MatchPhase::Group) {
            $this->standingService->applyGroupResult($match);
        }
        $this->statsService->applyMatchResult($match);
        $this->bracketGeneratorService->propagate($match);

        $tournament = $match->getTournament();
        if ($tournament) {
            $this->bracketGeneratorService->resolvePendingByes($tournament);
        }

        if ($tournament && $match->getPhase() === MatchPhase::Final && $match->getWinner()) {
            $pendingFinals = array_filter(
                $tournament->getMatches()->toArray(),
                static fn (GameMatch $m) => $m->getPhase() === MatchPhase::Final && $m->getStatus() !== MatchStatus::Done
            );
            if (0 === count($pendingFinals)) {
                $tournament->setStatus(TournamentStatus::Finished);
            }
        }

        // Ne pas faire avancer la projection lors d’une simple correction
        if (!$alreadyDone && $tournament) {
            $this->advanceDisplayToNextMatch($tournament, $match);
        }

        $this->em->flush();

        return $match;
    }

    /**
     * Un match terminé est corrigible tant qu’aucune des deux équipes
     * n’a rejoué un match ultérieur, et que les matchs suivants ne sont pas joués.
     */
    public function canCorrect(GameMatch $match): bool
    {
        if ($match->getStatus() !== MatchStatus::Done || null === $match->getWinner()) {
            return false;
        }

        $tournament = $match->getTournament();
        $home = $match->getHomeTeam();
        $away = $match->getAwayTeam();
        if (!$tournament || !$home || !$away) {
            return false;
        }

        $homeId = $home->getId();
        $awayId = $away->getId();

        foreach ($tournament->getMatches() as $other) {
            if ($other->getId() === $match->getId()) {
                continue;
            }
            if ($other->getStatus() !== MatchStatus::Done) {
                continue;
            }

            $otherHome = $other->getHomeTeam()?->getId();
            $otherAway = $other->getAwayTeam()?->getId();
            $involves = $otherHome === $homeId || $otherHome === $awayId
                || $otherAway === $homeId || $otherAway === $awayId;
            if (!$involves) {
                continue;
            }

            if (
                $other->getSortOrder() > $match->getSortOrder()
                || ($other->getSortOrder() === $match->getSortOrder() && ($other->getId() ?? 0) > ($match->getId() ?? 0))
            ) {
                return false;
            }
        }

        if ($match->getNextMatchId()) {
            $next = $this->em->find(GameMatch::class, $match->getNextMatchId());
            if ($next && $next->getStatus() === MatchStatus::Done) {
                return false;
            }
        }
        if ($match->getLoserNextMatchId()) {
            $lb = $this->em->find(GameMatch::class, $match->getLoserNextMatchId());
            if ($lb && $lb->getStatus() === MatchStatus::Done) {
                return false;
            }
        }

        return true;
    }

    /** Retire les équipes placées par ce match dans les matchs suivants. */
    private function clearPropagation(GameMatch $match): void
    {
        $winner = $match->getWinner();
        $home = $match->getHomeTeam();
        $away = $match->getAwayTeam();
        if (!$winner || !$home || !$away) {
            return;
        }
        $loser = $winner->getId() === $home->getId() ? $away : $home;

        if ($match->getNextMatchId()) {
            $next = $this->em->find(GameMatch::class, $match->getNextMatchId());
            if ($next && $next->getStatus() !== MatchStatus::Done) {
                if ($match->getNextMatchIsHome() && $next->getHomeTeam()?->getId() === $winner->getId()) {
                    $next->setHomeTeam(null);
                }
                if (!$match->getNextMatchIsHome() && $next->getAwayTeam()?->getId() === $winner->getId()) {
                    $next->setAwayTeam(null);
                }
                $next->setWinner(null);
                if ($next->getStatus() === MatchStatus::Live) {
                    $next->setStatus(MatchStatus::Pending);
                }
            }
        }

        if ($match->getLoserNextMatchId()) {
            $lb = $this->em->find(GameMatch::class, $match->getLoserNextMatchId());
            if ($lb && $lb->getStatus() !== MatchStatus::Done) {
                if ($match->getLoserNextMatchIsHome() && $lb->getHomeTeam()?->getId() === $loser->getId()) {
                    $lb->setHomeTeam(null);
                }
                if (!$match->getLoserNextMatchIsHome() && $lb->getAwayTeam()?->getId() === $loser->getId()) {
                    $lb->setAwayTeam(null);
                }
                $lb->setWinner(null);
                if ($lb->getStatus() === MatchStatus::Live) {
                    $lb->setStatus(MatchStatus::Pending);
                }
            }
        }
    }

    /**
     * Après un résultat : projette le prochain match jouable (ordre du calendrier).
     */
    private function advanceDisplayToNextMatch(Tournament $tournament, GameMatch $finished): void
    {
        foreach ($tournament->getMatches() as $m) {
            if ($m->getStatus() === MatchStatus::Live && $m->getId() !== $finished->getId()) {
                $m->setStatus(MatchStatus::Pending);
            }
        }

        $candidates = array_values(array_filter(
            $tournament->getMatches()->toArray(),
            static fn (GameMatch $m) => $m->getId() !== $finished->getId()
                && $m->getStatus() !== MatchStatus::Done
                && null !== $m->getHomeTeam()
                && null !== $m->getAwayTeam()
        ));

        usort($candidates, static function (GameMatch $a, GameMatch $b): int {
            return [$a->getSortOrder(), $a->getId()] <=> [$b->getSortOrder(), $b->getId()];
        });

        // Préférer le match suivant dans le calendrier, sinon le premier encore ouvert
        $nextPlayable = null;
        foreach ($candidates as $candidate) {
            if ($candidate->getSortOrder() > $finished->getSortOrder()) {
                $nextPlayable = $candidate;
                break;
            }
        }
        if (null === $nextPlayable && [] !== $candidates) {
            $nextPlayable = $candidates[0];
        }

        if (null === $nextPlayable) {
            $tournament->setCurrentMatchId(null);
            $tournament->setNextMatchId(null);

            return;
        }

        $nextPlayable->setStatus(MatchStatus::Live);
        $tournament->setCurrentMatchId($nextPlayable->getId());

        $following = null;
        foreach ($candidates as $candidate) {
            if ($candidate->getId() === $nextPlayable->getId()) {
                continue;
            }
            if ($candidate->getSortOrder() > $nextPlayable->getSortOrder()) {
                $following = $candidate;
                break;
            }
        }
        if (null === $following) {
            foreach ($candidates as $candidate) {
                if ($candidate->getId() !== $nextPlayable->getId()) {
                    $following = $candidate;
                    break;
                }
            }
        }

        $tournament->setNextMatchId($following?->getId());
    }

    public function setDisplay(Tournament $tournament, ?int $currentMatchId, ?int $nextMatchId): Tournament
    {
        if ($currentMatchId) {
            $current = $this->em->find(GameMatch::class, $currentMatchId);
            if (!$current || $current->getTournament()?->getId() !== $tournament->getId()) {
                throw new \InvalidArgumentException('Match courant invalide.');
            }
            foreach ($tournament->getMatches() as $m) {
                if ($m->getStatus() === MatchStatus::Live && $m->getId() !== $currentMatchId) {
                    $m->setStatus(MatchStatus::Pending);
                }
            }
            if ($current->getStatus() !== MatchStatus::Done) {
                $current->setStatus(MatchStatus::Live);
            }
            $tournament->setCurrentMatchId($currentMatchId);
        } else {
            $tournament->setCurrentMatchId(null);
        }

        $tournament->setNextMatchId($nextMatchId);
        $this->em->flush();

        return $tournament;
    }

    /**
     * Recalcule la progression WB→LB à partir des résultats déjà saisis.
     */
    public function resyncBracket(Tournament $tournament): Tournament
    {
        foreach ($tournament->getMatches() as $match) {
            if ($match->getPhase() === MatchPhase::Group) {
                continue;
            }
            if ($match->getPhase() === MatchPhase::Loser
                || $match->getPhase() === MatchPhase::Final
                || ($match->getPhase() === MatchPhase::Winner && $match->getRound() > 1)) {
                if ($match->getStatus() !== MatchStatus::Done) {
                    $match->setHomeTeam(null);
                    $match->setAwayTeam(null);
                    $match->setWinner(null);
                }
            }
        }

        $done = array_values(array_filter(
            $tournament->getMatches()->toArray(),
            static fn (GameMatch $m) => $m->getPhase() !== MatchPhase::Group
                && $m->getStatus() === MatchStatus::Done
                && null !== $m->getWinner()
        ));
        usort($done, static function (GameMatch $a, GameMatch $b): int {
            $phaseOrder = [
                MatchPhase::Winner->value => 1,
                MatchPhase::Loser->value => 2,
                MatchPhase::Final->value => 3,
            ];

            return [$phaseOrder[$a->getPhase()->value] ?? 9, $a->getRound(), $a->getSlot()]
                <=> [$phaseOrder[$b->getPhase()->value] ?? 9, $b->getRound(), $b->getSlot()];
        });

        foreach ($done as $match) {
            $this->bracketGeneratorService->propagate($match);
        }
        $this->bracketGeneratorService->resolvePendingByes($tournament);
        $this->em->flush();

        return $tournament;
    }

    /**
     * Régénère le bracket (câblage corrigé) puis réapplique les résultats WB déjà connus.
     */
    public function rebuildBracketPreservingResults(Tournament $tournament): Tournament
    {
        $saved = [];
        foreach ($tournament->getMatches() as $match) {
            if ($match->getPhase() !== MatchPhase::Winner && $match->getPhase() !== MatchPhase::Final) {
                continue;
            }
            if ($match->getStatus() !== MatchStatus::Done || !$match->getWinner()) {
                continue;
            }
            if (!$match->getHomeTeam() || !$match->getAwayTeam()) {
                continue;
            }
            $saved[] = [
                'phase' => $match->getPhase()->value,
                'round' => $match->getRound(),
                'slot' => $match->getSlot(),
                'homeId' => $match->getHomeTeam()->getId(),
                'awayId' => $match->getAwayTeam()->getId(),
                'winnerId' => $match->getWinner()->getId(),
                'scoreHome' => $match->getScoreHome(),
                'scoreAway' => $match->getScoreAway(),
            ];
        }

        $this->bracketGeneratorService->generate($tournament);
        $this->em->refresh($tournament);

        usort($saved, static fn (array $a, array $b) => [$a['round'], $a['slot']] <=> [$b['round'], $b['slot']]);

        foreach ($saved as $row) {
            if ($row['phase'] === MatchPhase::Final->value) {
                continue; // réappliqué via progression
            }
            foreach ($tournament->getMatches() as $match) {
                if ($match->getPhase()->value !== $row['phase']) {
                    continue;
                }
                if ($match->getRound() !== $row['round'] || $match->getSlot() !== $row['slot']) {
                    continue;
                }
                $home = $match->getHomeTeam();
                $away = $match->getAwayTeam();
                if (!$home || !$away) {
                    continue;
                }
                $ids = [$home->getId(), $away->getId()];
                if (!in_array($row['homeId'], $ids, true) || !in_array($row['awayId'], $ids, true)) {
                    continue;
                }
                if ($match->getStatus() === MatchStatus::Done) {
                    continue;
                }
                $winner = $row['winnerId'] === $home->getId() ? $home : $away;
                if ($winner->getId() !== $row['winnerId']) {
                    $winner = $row['winnerId'] === $away->getId() ? $away : $home;
                }
                // Appliquer sans recompter les stats (déjà comptées)
                $match->setWinner($winner);
                $match->setScoreHome($row['scoreHome']);
                $match->setScoreAway($row['scoreAway']);
                $match->setStatus(MatchStatus::Done);
                $this->bracketGeneratorService->propagate($match);
                $this->bracketGeneratorService->resolvePendingByes($tournament);
            }
        }

        $this->em->flush();

        return $tournament;
    }
}
