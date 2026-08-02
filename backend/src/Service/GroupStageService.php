<?php

namespace App\Service;

use App\Entity\GameMatch;
use App\Entity\GroupStanding;
use App\Entity\Team;
use App\Entity\Tournament;
use App\Entity\TournamentGroup;
use App\Enum\MatchPhase;
use App\Enum\MatchStatus;
use App\Enum\TeamMode;
use App\Enum\TournamentStatus;
use Doctrine\ORM\EntityManagerInterface;

class GroupStageService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function generate(Tournament $tournament): Tournament
    {
        if (!$tournament->hasGroupStage()) {
            throw new \InvalidArgumentException('Ce tournoi n\'a pas de phase de poules.');
        }

        $teams = $tournament->getTeams()->toArray();
        usort($teams, static fn (Team $a, Team $b) => $a->getSeed() <=> $b->getSeed());

        if (count($teams) < 2) {
            throw new \InvalidArgumentException('Pas assez d\'équipes.');
        }

        foreach ($tournament->getMatches()->toArray() as $match) {
            if (in_array($match->getPhase(), [MatchPhase::Group, MatchPhase::Tiebreaker], true)) {
                $tournament->getMatches()->removeElement($match);
                $this->em->remove($match);
            }
        }
        foreach ($tournament->getGroups()->toArray() as $group) {
            foreach ($group->getTeams() as $team) {
                $team->setGroup(null);
            }
            $tournament->getGroups()->removeElement($group);
            $this->em->remove($group);
        }
        $this->em->flush();

        $groupCount = max(1, min($tournament->getGroupCount(), count($teams)));
        $groups = [];
        for ($i = 0; $i < $groupCount; ++$i) {
            $group = (new TournamentGroup())
                ->setTournament($tournament)
                ->setName('Poule '.chr(65 + $i));
            $tournament->addGroup($group);
            $this->em->persist($group);
            $groups[] = $group;
        }

        $direction = 1;
        $index = 0;
        foreach ($teams as $team) {
            $group = $groups[$index];
            $team->setGroup($group);
            $standing = (new GroupStanding())->setTeam($team);
            $group->addStanding($standing);
            $this->em->persist($standing);

            $index += $direction;
            if ($index >= $groupCount || $index < 0) {
                $direction *= -1;
                $index += $direction;
            }
        }

        /** @var array<int, list<list<array{0: Team, 1: Team}>>> $schedules */
        $schedules = [];
        foreach ($groups as $gIndex => $group) {
            $groupTeams = array_values(array_filter($teams, static fn (Team $t) => $t->getGroup() === $group));
            $schedules[$gIndex] = $this->circleMethodRounds($groupTeams);
        }

        $maxRounds = 0;
        foreach ($schedules as $rounds) {
            $maxRounds = max($maxRounds, count($rounds));
        }

        $sort = 0;
        for ($round = 0; $round < $maxRounds; ++$round) {
            $maxMatchesInRound = 0;
            foreach ($schedules as $rounds) {
                $maxMatchesInRound = max($maxMatchesInRound, count($rounds[$round] ?? []));
            }

            for ($slot = 0; $slot < $maxMatchesInRound; ++$slot) {
                foreach ($groups as $gIndex => $group) {
                    $pair = $schedules[$gIndex][$round][$slot] ?? null;
                    if (null === $pair) {
                        continue;
                    }

                    [$home, $away] = $pair;
                    $match = (new GameMatch())
                        ->setTournament($tournament)
                        ->setGroup($group)
                        ->setPhase(MatchPhase::Group)
                        ->setRound($round + 1)
                        ->setSlot(++$sort)
                        ->setSortOrder($sort)
                        ->setHomeTeam($home)
                        ->setAwayTeam($away)
                        ->setStatus(MatchStatus::Pending);
                    $tournament->addMatch($match);
                    $this->em->persist($match);
                }
            }
        }

        $tournament->setStatus(TournamentStatus::Groups);
        $this->em->flush();

        return $tournament;
    }

    /** @param list<Team> $teams @return list<list<array{0: Team, 1: Team}>> */
    private function circleMethodRounds(array $teams): array
    {
        $n = count($teams);
        if ($n < 2) {
            return [];
        }

        $slots = $teams;
        if ($n % 2 === 1) {
            $slots[] = null;
            ++$n;
        }

        $rounds = [];
        $roundCount = $n - 1;
        $half = (int) ($n / 2);

        for ($r = 0; $r < $roundCount; ++$r) {
            $pairs = [];
            for ($i = 0; $i < $half; ++$i) {
                $home = $slots[$i];
                $away = $slots[$n - 1 - $i];
                if (null === $home || null === $away) {
                    continue;
                }
                $pairs[] = $r % 2 === 1 ? [$away, $home] : [$home, $away];
            }
            $rounds[] = $pairs;

            $fixed = array_shift($slots);
            $last = array_pop($slots);
            array_unshift($slots, $last);
            array_unshift($slots, $fixed);
        }

        return $rounds;
    }

    public function assertGroupStageComplete(Tournament $tournament): void
    {
        if (!$tournament->hasGroupStage()) {
            return;
        }

        $summary = $this->getStageSummary($tournament);
        if (!$summary['complete']) {
            throw new \InvalidArgumentException(sprintf(
                'Impossible de générer le bracket : %d match(s) de poule sans résultat.',
                ($summary['total'] - $summary['done'])
            ));
        }

        if (!$summary['tiebreakersComplete']) {
            throw new \InvalidArgumentException(sprintf(
                'Impossible de générer le bracket : %d barrage(s) sans résultat.',
                $summary['tiebreakersPending']
            ));
        }

        if ([] !== $summary['ties']) {
            throw new \InvalidArgumentException(
                'Des égalités empêchent la qualification. Lancez un barrage (1v1 ou chacun contre chacun) avant le bracket.'
            );
        }
    }

    public function getStageSummary(Tournament $tournament): array
    {
        if (!$tournament->hasGroupStage()) {
            return [
                'total' => 0,
                'done' => 0,
                'complete' => true,
                'tiebreakersTotal' => 0,
                'tiebreakersDone' => 0,
                'tiebreakersPending' => 0,
                'tiebreakersComplete' => true,
                'ties' => [],
                'readyForBracket' => true,
            ];
        }

        $groupMatches = array_values(array_filter(
            $tournament->getMatches()->toArray(),
            static fn (GameMatch $m) => $m->getPhase() === MatchPhase::Group
        ));
        $done = count(array_filter(
            $groupMatches,
            static fn (GameMatch $m) => $m->getStatus() === MatchStatus::Done && null !== $m->getWinner()
        ));
        $total = count($groupMatches);
        $complete = $total > 0 && $done === $total;

        $tbMatches = array_values(array_filter(
            $tournament->getMatches()->toArray(),
            static fn (GameMatch $m) => $m->getPhase() === MatchPhase::Tiebreaker
        ));
        $tbDone = count(array_filter(
            $tbMatches,
            static fn (GameMatch $m) => $m->getStatus() === MatchStatus::Done && null !== $m->getWinner()
        ));
        $tbTotal = count($tbMatches);
        $tbPending = $tbTotal - $tbDone;
        $tbComplete = 0 === $tbPending;

        $ties = $complete ? $this->detectUnresolvedTies($tournament) : [];

        return [
            'total' => $total,
            'done' => $done,
            'complete' => $complete,
            'tiebreakersTotal' => $tbTotal,
            'tiebreakersDone' => $tbDone,
            'tiebreakersPending' => $tbPending,
            'tiebreakersComplete' => $tbComplete,
            'ties' => $ties,
            'readyForBracket' => $complete && $tbComplete && [] === $ties,
        ];
    }

    /**
     * @return list<array{
     *   groupId: int,
     *   groupName: string,
     *   spots: int,
     *   teams: list<array>,
     *   modes: list<array{id: string, label: string}>
     * }>
     */
    public function detectUnresolvedTies(Tournament $tournament): array
    {
        $ties = [];
        $perGroup = $tournament->getQualifiersPerGroup();
        $duo = $tournament->getTeamMode() === TeamMode::Duo;

        foreach ($tournament->getGroups() as $group) {
            $cluster = $this->findCutoffCluster($group, $perGroup);
            if (null === $cluster) {
                continue;
            }

            [$standings, $spots] = $cluster;
            if ($this->isClusterResolved($standings, $spots, $tournament)) {
                continue;
            }

            $teamCount = count($standings);
            $modes = [];
            if ($teamCount === 2) {
                $modes[] = [
                    'id' => 'duel',
                    'label' => $duo ? 'Match barrage 2v2' : 'Match barrage 1v1',
                ];
            } else {
                $modes[] = [
                    'id' => 'duel',
                    'label' => $duo
                        ? 'Mini-tableau 2v2 (demi puis finale)'
                        : 'Mini-tableau 1v1 (demi puis finale)',
                ];
                $modes[] = [
                    'id' => 'melee',
                    'label' => $duo
                        ? 'Chacun contre chacun (2v2v2…)'
                        : 'Chacun contre chacun (1v1v1…)',
                ];
            }

            $ties[] = [
                'groupId' => $group->getId(),
                'groupName' => $group->getName(),
                'spots' => $spots,
                'teams' => array_map(
                    static fn (GroupStanding $s) => $s->getTeam()?->toArray(),
                    $standings
                ),
                'modes' => $modes,
            ];
        }

        return $ties;
    }

    /**
     * @return array{0: list<GroupStanding>, 1: int}|null
     */
    private function findCutoffCluster(TournamentGroup $group, int $perGroup): ?array
    {
        $standings = $this->sortedStandings($group);
        if ([] === $standings || $perGroup <= 0) {
            return null;
        }

        $needed = $perGroup;
        $i = 0;
        $n = count($standings);

        while ($i < $n && $needed > 0) {
            $key = $this->standingKey($standings[$i]);
            $j = $i;
            while ($j < $n && $this->standingKey($standings[$j]) === $key) {
                ++$j;
            }
            $clusterSize = $j - $i;

            if ($clusterSize <= $needed) {
                $needed -= $clusterSize;
                $i = $j;
                continue;
            }

            return [array_slice($standings, $i, $clusterSize), $needed];
        }

        return null;
    }

    /** @param list<GroupStanding> $standings */
    private function isClusterResolved(array $standings, int $spots, Tournament $tournament): bool
    {
        return null !== $this->orderClusterByTiebreakers($standings, $spots, $tournament);
    }

    /**
     * @param list<GroupStanding> $standings
     *
     * @return list<GroupStanding>|null ordered for qualification, or null if unresolved
     */
    private function orderClusterByTiebreakers(array $standings, int $spots, Tournament $tournament): ?array
    {
        $teams = [];
        foreach ($standings as $s) {
            if ($s->getTeam()) {
                $teams[$s->getTeam()->getId()] = $s;
            }
        }
        $teamIds = array_keys($teams);
        if (count($teamIds) < 2) {
            return $standings;
        }

        $tbMatches = array_values(array_filter(
            $tournament->getMatches()->toArray(),
            static function (GameMatch $m) use ($teamIds): bool {
                if ($m->getPhase() !== MatchPhase::Tiebreaker || $m->getStatus() !== MatchStatus::Done) {
                    return false;
                }
                $homeId = $m->getHomeTeam()?->getId();
                $awayId = $m->getAwayTeam()?->getId();

                return $homeId && $awayId
                    && in_array($homeId, $teamIds, true)
                    && in_array($awayId, $teamIds, true);
            }
        ));

        if ([] === $tbMatches) {
            return null;
        }

        $stats = [];
        foreach ($teamIds as $id) {
            $stats[$id] = ['wins' => 0, 'losses' => 0, 'for' => 0, 'against' => 0, 'played' => 0];
        }

        foreach ($tbMatches as $match) {
            $homeId = $match->getHomeTeam()->getId();
            $awayId = $match->getAwayTeam()->getId();
            $winnerId = $match->getWinner()?->getId();
            if (!$winnerId || !isset($stats[$homeId], $stats[$awayId])) {
                continue;
            }
            ++$stats[$homeId]['played'];
            ++$stats[$awayId]['played'];
            $stats[$homeId]['for'] += (int) ($match->getScoreHome() ?? 0);
            $stats[$homeId]['against'] += (int) ($match->getScoreAway() ?? 0);
            $stats[$awayId]['for'] += (int) ($match->getScoreAway() ?? 0);
            $stats[$awayId]['against'] += (int) ($match->getScoreHome() ?? 0);
            if ($winnerId === $homeId) {
                ++$stats[$homeId]['wins'];
                ++$stats[$awayId]['losses'];
            } else {
                ++$stats[$awayId]['wins'];
                ++$stats[$homeId]['losses'];
            }
        }

        // Pour 2 équipes : 1 match suffit. Pour 3+ en melee : au moins un match joué par équipe
        // et classement unique sur les $spots premières places.
        $pendingTb = array_filter(
            $tournament->getMatches()->toArray(),
            static function (GameMatch $m) use ($teamIds): bool {
                if ($m->getPhase() !== MatchPhase::Tiebreaker || $m->getStatus() === MatchStatus::Done) {
                    return false;
                }
                $homeId = $m->getHomeTeam()?->getId();
                $awayId = $m->getAwayTeam()?->getId();

                return $homeId && $awayId
                    && in_array($homeId, $teamIds, true)
                    && in_array($awayId, $teamIds, true);
            }
        );
        if ([] !== $pendingTb) {
            return null;
        }

        $maxRound = 0;
        foreach ($tbMatches as $match) {
            $maxRound = max($maxRound, $match->getRound());
        }
        $decisive = array_values(array_filter(
            $tbMatches,
            static fn (GameMatch $m) => $m->getRound() === $maxRound
        ));

        // 1 place + un match décisif unique (duel simple ou finale de mini-tableau)
        if (1 === $spots && 1 === count($decisive) && $decisive[0]->getWinner()) {
            $winnerId = $decisive[0]->getWinner()->getId();
            $ordered = [];
            if (isset($teams[$winnerId])) {
                $ordered[] = $teams[$winnerId];
                unset($teams[$winnerId]);
            }
            uasort($teams, static function (GroupStanding $a, GroupStanding $b) use ($stats): int {
                $sa = $stats[$a->getTeam()->getId()];
                $sb = $stats[$b->getTeam()->getId()];

                return [$sb['wins'], $sb['for'] - $sb['against']]
                    <=> [$sa['wins'], $sa['for'] - $sa['against']];
            });

            return array_merge($ordered, array_values($teams));
        }

        uasort($teams, static function (GroupStanding $a, GroupStanding $b) use ($stats): int {
            $sa = $stats[$a->getTeam()->getId()];
            $sb = $stats[$b->getTeam()->getId()];
            $da = $sa['for'] - $sa['against'];
            $db = $sb['for'] - $sb['against'];

            return [$sb['wins'], $db, $sb['for'], $a->getTeam()->getSeed()]
                <=> [$sa['wins'], $da, $sa['for'], $b->getTeam()->getSeed()];
        });

        $ordered = array_values($teams);

        if (count($ordered) > $spots) {
            $lastIn = $stats[$ordered[$spots - 1]->getTeam()->getId()];
            $firstOut = $stats[$ordered[$spots]->getTeam()->getId()];
            $keyIn = [$lastIn['wins'], $lastIn['for'] - $lastIn['against'], $lastIn['for']];
            $keyOut = [$firstOut['wins'], $firstOut['for'] - $firstOut['against'], $firstOut['for']];
            if ($keyIn === $keyOut) {
                return null;
            }
        }

        return $ordered;
    }

    public function createTiebreakers(Tournament $tournament, int $groupId, string $mode): Tournament
    {
        if (!in_array($mode, ['duel', 'melee'], true)) {
            throw new \InvalidArgumentException('Mode invalide (duel ou melee).');
        }

        $summary = $this->getStageSummary($tournament);
        if (!$summary['complete']) {
            throw new \InvalidArgumentException('Tous les matchs de poule doivent être terminés.');
        }

        $tie = null;
        foreach ($summary['ties'] as $item) {
            if ((int) $item['groupId'] === $groupId) {
                $tie = $item;
                break;
            }
        }
        if (null === $tie) {
            throw new \InvalidArgumentException('Aucune égalité à résoudre pour cette poule.');
        }

        $group = null;
        foreach ($tournament->getGroups() as $g) {
            if ($g->getId() === $groupId) {
                $group = $g;
                break;
            }
        }
        if (!$group) {
            throw new \InvalidArgumentException('Poule introuvable.');
        }

        /** @var list<Team> $teams */
        $teams = [];
        foreach ($tie['teams'] as $teamData) {
            if (!$teamData || !isset($teamData['id'])) {
                continue;
            }
            foreach ($group->getStandings() as $standing) {
                if ($standing->getTeam()?->getId() === (int) $teamData['id']) {
                    $teams[] = $standing->getTeam();
                    break;
                }
            }
        }

        if (count($teams) < 2) {
            throw new \InvalidArgumentException('Pas assez d\'équipes pour un barrage.');
        }

        // Supprimer les anciens barrages incomplets de cette poule pour ces équipes
        $teamIds = array_map(static fn (Team $t) => $t->getId(), $teams);
        foreach ($tournament->getMatches()->toArray() as $match) {
            if ($match->getPhase() !== MatchPhase::Tiebreaker) {
                continue;
            }
            $homeId = $match->getHomeTeam()?->getId();
            $awayId = $match->getAwayTeam()?->getId();
            $involves = $homeId && $awayId
                && in_array($homeId, $teamIds, true)
                && in_array($awayId, $teamIds, true);
            if ($involves && $match->getStatus() !== MatchStatus::Done) {
                $tournament->getMatches()->removeElement($match);
                $this->em->remove($match);
            }
        }
        $this->em->flush();

        $sortBase = 9000;
        foreach ($tournament->getMatches() as $m) {
            $sortBase = max($sortBase, $m->getSortOrder());
        }

        if (2 === count($teams) || 'melee' === $mode) {
            $this->createRoundRobinTiebreakers($tournament, $group, $teams, $sortBase);
        } else {
            // Mini-tableau pour 3+ en mode duel
            $this->createMiniBracketTiebreakers($tournament, $group, $teams, $sortBase, (int) $tie['spots']);
        }

        $this->em->flush();

        return $tournament;
    }

    /** @param list<Team> $teams */
    private function createRoundRobinTiebreakers(
        Tournament $tournament,
        TournamentGroup $group,
        array $teams,
        int $sortBase,
    ): void {
        $n = count($teams);
        $slot = 0;
        for ($i = 0; $i < $n; ++$i) {
            for ($j = $i + 1; $j < $n; ++$j) {
                $match = (new GameMatch())
                    ->setTournament($tournament)
                    ->setGroup($group)
                    ->setPhase(MatchPhase::Tiebreaker)
                    ->setRound(1)
                    ->setSlot(++$slot)
                    ->setSortOrder(++$sortBase)
                    ->setHomeTeam($teams[$i])
                    ->setAwayTeam($teams[$j])
                    ->setStatus(MatchStatus::Pending);
                $tournament->addMatch($match);
                $this->em->persist($match);
            }
        }
    }

    /**
     * Mini-tableau : pour 3 équipes / 1 place → A (meilleure seed) attend, B vs C, vainqueur vs A.
     * Pour 3 équipes / 2 places → round-robin (plus juste) forcé.
     *
     * @param list<Team> $teams
     */
    private function createMiniBracketTiebreakers(
        Tournament $tournament,
        TournamentGroup $group,
        array $teams,
        int $sortBase,
        int $spots,
    ): void {
        if ($spots > 1) {
            // Plusieurs places : le chacun-contre-chacun est plus fiable
            $this->createRoundRobinTiebreakers($tournament, $group, $teams, $sortBase);

            return;
        }

        usort($teams, static fn (Team $a, Team $b) => $a->getSeed() <=> $b->getSeed());

        if (3 === count($teams) && 1 === $spots) {
            $favorite = $teams[0];
            $challengerA = $teams[1];
            $challengerB = $teams[2];

            $semi = (new GameMatch())
                ->setTournament($tournament)
                ->setGroup($group)
                ->setPhase(MatchPhase::Tiebreaker)
                ->setRound(1)
                ->setSlot(1)
                ->setSortOrder(++$sortBase)
                ->setHomeTeam($challengerA)
                ->setAwayTeam($challengerB)
                ->setStatus(MatchStatus::Pending);
            $tournament->addMatch($semi);
            $this->em->persist($semi);
            $this->em->flush();

            $final = (new GameMatch())
                ->setTournament($tournament)
                ->setGroup($group)
                ->setPhase(MatchPhase::Tiebreaker)
                ->setRound(2)
                ->setSlot(1)
                ->setSortOrder(++$sortBase)
                ->setHomeTeam($favorite)
                ->setAwayTeam(null)
                ->setStatus(MatchStatus::Pending);
            $tournament->addMatch($final);
            $this->em->persist($final);
            $this->em->flush();

            $semi->setNextMatchId($final->getId());
            $semi->setNextMatchIsHome(false);
            $this->em->flush();

            return;
        }

        // Fallback : round-robin
        $this->createRoundRobinTiebreakers($tournament, $group, $teams, $sortBase);
    }

    /** @return list<Team> */
    public function getQualifiers(Tournament $tournament): array
    {
        $qualifiers = [];
        $perGroup = $tournament->getQualifiersPerGroup();

        foreach ($tournament->getGroups() as $group) {
            foreach ($this->getGroupQualifiers($group, $perGroup, $tournament) as $team) {
                $qualifiers[] = $team;
            }
        }

        return $qualifiers;
    }

    /** @return list<Team> */
    private function getGroupQualifiers(TournamentGroup $group, int $perGroup, Tournament $tournament): array
    {
        $standings = $this->sortedStandings($group);
        $result = [];
        $needed = $perGroup;
        $i = 0;
        $n = count($standings);

        while ($i < $n && $needed > 0) {
            $key = $this->standingKey($standings[$i]);
            $j = $i;
            while ($j < $n && $this->standingKey($standings[$j]) === $key) {
                ++$j;
            }
            $cluster = array_slice($standings, $i, $j - $i);
            $clusterSize = count($cluster);

            if ($clusterSize <= $needed) {
                foreach ($cluster as $standing) {
                    if ($standing->getTeam()) {
                        $result[] = $standing->getTeam();
                    }
                }
                $needed -= $clusterSize;
                $i = $j;
                continue;
            }

            $ordered = $this->orderClusterByTiebreakers($cluster, $needed, $tournament);
            if (null === $ordered) {
                throw new \InvalidArgumentException(sprintf(
                    'Égalité non résolue dans %s.',
                    $group->getName()
                ));
            }
            foreach (array_slice($ordered, 0, $needed) as $standing) {
                if ($standing->getTeam()) {
                    $result[] = $standing->getTeam();
                }
            }
            $needed = 0;
            $i = $j;
        }

        return $result;
    }

    /** @return list<GroupStanding> */
    private function sortedStandings(TournamentGroup $group): array
    {
        $standings = $group->getStandings()->toArray();
        usort($standings, function (GroupStanding $a, GroupStanding $b): int {
            return $this->standingKey($b) <=> $this->standingKey($a)
                ?: ($a->getTeam()?->getSeed() ?? 0) <=> ($b->getTeam()?->getSeed() ?? 0);
        });

        return $standings;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function standingKey(GroupStanding $standing): array
    {
        return [$standing->getPoints(), $standing->getWins(), $standing->getScoreDiff()];
    }
}
