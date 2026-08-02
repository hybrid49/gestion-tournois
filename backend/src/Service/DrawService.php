<?php

namespace App\Service;

use App\Entity\Team;
use App\Entity\Tournament;
use App\Enum\TeamMode;
use Doctrine\ORM\EntityManagerInterface;

class DrawService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @param list<Team> $teams */
    public function shuffleTeams(array $teams): array
    {
        $n = count($teams);
        for ($i = $n - 1; $i > 0; --$i) {
            $j = random_int(0, $i);
            [$teams[$i], $teams[$j]] = [$teams[$j], $teams[$i]];
        }

        foreach ($teams as $index => $team) {
            $team->setSeed($index + 1);
        }

        $this->em->flush();

        return $teams;
    }

    public function drawTournament(Tournament $tournament): array
    {
        $teams = $tournament->getTeams()->toArray();
        if (count($teams) < 2) {
            throw new \InvalidArgumentException('Il faut au moins 2 équipes pour le tirage.');
        }

        return $this->shuffleTeams($teams);
    }

    /**
     * Pair unpaired solo players into duo teams randomly.
     *
     * @param list<\App\Entity\Player> $players
     */
    public function formRandomDuos(Tournament $tournament, array $players): array
    {
        if ($tournament->getTeamMode() !== TeamMode::Duo) {
            throw new \InvalidArgumentException('Le tournoi n\'est pas en mode duo.');
        }

        $n = count($players);
        for ($i = $n - 1; $i > 0; --$i) {
            $j = random_int(0, $i);
            [$players[$i], $players[$j]] = [$players[$j], $players[$i]];
        }

        if (count($players) % 2 !== 0) {
            throw new \InvalidArgumentException('Nombre impair de joueurs pour former des duos.');
        }

        $created = [];
        for ($i = 0; $i < count($players); $i += 2) {
            $p1 = $players[$i];
            $p2 = $players[$i + 1];
            $team = (new Team())
                ->setTournament($tournament)
                ->setPlayer1($p1)
                ->setPlayer2($p2)
                ->setName($p1->getName().' / '.$p2->getName());
            $tournament->addTeam($team);
            $this->em->persist($team);
            $created[] = $team;
        }

        $this->em->flush();

        return $created;
    }
}
