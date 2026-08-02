<?php

namespace App\Service;

use App\Entity\GameMatch;
use App\Entity\Player;
use App\Entity\Team;
use App\Repository\GameMatchRepository;
use App\Repository\PlayerRepository;
use App\Repository\TournamentRepository;
use Doctrine\ORM\EntityManagerInterface;

class StatsService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GameMatchRepository $matchRepository,
        private readonly PlayerRepository $playerRepository,
        private readonly TournamentRepository $tournamentRepository,
    ) {
    }

    /**
     * Remet les compteurs joueurs à zéro et supprime tous les tournois
     * (l’historique détaillé est dérivé des matchs).
     *
     * @return array{playersReset: int, tournamentsDeleted: int}
     */
    public function clearAllHistory(): array
    {
        $tournaments = $this->tournamentRepository->findAll();
        $tournamentsDeleted = count($tournaments);
        foreach ($tournaments as $tournament) {
            $this->em->remove($tournament);
        }

        $players = $this->playerRepository->findAll();
        foreach ($players as $player) {
            $player->setWins(0);
            $player->setLosses(0);
            $player->setTournamentsPlayed(0);
        }

        $this->em->flush();

        return [
            'playersReset' => count($players),
            'tournamentsDeleted' => $tournamentsDeleted,
        ];
    }

    public function applyMatchResult(GameMatch $match): void
    {
        $this->mutateMatchResult($match, false);
    }

    public function revertMatchResult(GameMatch $match): void
    {
        $this->mutateMatchResult($match, true);
    }

    private function mutateMatchResult(GameMatch $match, bool $revert): void
    {
        $winner = $match->getWinner();
        $home = $match->getHomeTeam();
        $away = $match->getAwayTeam();
        if (!$winner || !$home || !$away) {
            return;
        }

        $loser = $winner->getId() === $home->getId() ? $away : $home;
        foreach ($winner->getPlayers() as $player) {
            if ($revert) {
                $player->removeWin();
            } else {
                $player->addWin();
            }
        }
        foreach ($loser->getPlayers() as $player) {
            if ($revert) {
                $player->removeLoss();
            } else {
                $player->addLoss();
            }
        }

        $this->em->flush();
    }

    public function markTournamentParticipation(Team $team): void
    {
        foreach ($team->getPlayers() as $player) {
            $player->incrementTournamentsPlayed();
        }
        $this->em->flush();
    }

    public function unmarkTournamentParticipation(Team $team): void
    {
        foreach ($team->getPlayers() as $player) {
            $player->decrementTournamentsPlayed();
        }
        $this->em->flush();
    }

    public function playerStats(Player $player): array
    {
        $matches = $this->matchRepository->createQueryBuilder('m')
            ->leftJoin('m.homeTeam', 'ht')
            ->leftJoin('m.awayTeam', 'at')
            ->where('ht.player1 = :p OR ht.player2 = :p OR at.player1 = :p OR at.player2 = :p')
            ->andWhere('m.winner IS NOT NULL')
            ->setParameter('p', $player)
            ->orderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult();

        $history = array_map(static function (GameMatch $m) use ($player) {
            $home = $m->getHomeTeam();
            $away = $m->getAwayTeam();
            $inHome = $home && in_array($player, $home->getPlayers(), true);
            $ownTeam = $inHome ? $home : $away;
            $won = $m->getWinner()?->getId() === $ownTeam?->getId();

            return [
                'match' => $m->toArray(),
                'won' => $won,
                'tournamentId' => $m->getTournament()?->getId(),
                'tournamentName' => $m->getTournament()?->getName(),
            ];
        }, $matches);

        return [
            'player' => $player->toArray(),
            'history' => $history,
        ];
    }
}
