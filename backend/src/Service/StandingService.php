<?php

namespace App\Service;

use App\Entity\GameMatch;
use App\Entity\GroupStanding;
use App\Entity\Team;
use App\Enum\MatchPhase;
use Doctrine\ORM\EntityManagerInterface;

class StandingService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function applyGroupResult(GameMatch $match): void
    {
        $this->mutateGroupResult($match, false);
    }

    public function revertGroupResult(GameMatch $match): void
    {
        $this->mutateGroupResult($match, true);
    }

    private function mutateGroupResult(GameMatch $match, bool $revert): void
    {
        if ($match->getPhase() !== MatchPhase::Group || !$match->getGroup() || !$match->getWinner()) {
            return;
        }

        $home = $match->getHomeTeam();
        $away = $match->getAwayTeam();
        $winner = $match->getWinner();
        if (!$home || !$away) {
            return;
        }

        $standings = $match->getGroup()->getStandings();
        $homeStanding = $this->findStanding($standings->toArray(), $home);
        $awayStanding = $this->findStanding($standings->toArray(), $away);

        if ($homeStanding) {
            $won = $winner->getId() === $home->getId();
            if ($revert) {
                $homeStanding->revertResult($won, $match->getScoreHome(), $match->getScoreAway());
            } else {
                $homeStanding->registerResult($won, $match->getScoreHome(), $match->getScoreAway());
            }
        }
        if ($awayStanding) {
            $won = $winner->getId() === $away->getId();
            if ($revert) {
                $awayStanding->revertResult($won, $match->getScoreAway(), $match->getScoreHome());
            } else {
                $awayStanding->registerResult($won, $match->getScoreAway(), $match->getScoreHome());
            }
        }

        $this->em->flush();
    }

    /** @param list<GroupStanding> $standings */
    private function findStanding(array $standings, Team $team): ?GroupStanding
    {
        foreach ($standings as $standing) {
            if ($standing->getTeam()?->getId() === $team->getId()) {
                return $standing;
            }
        }

        return null;
    }
}
