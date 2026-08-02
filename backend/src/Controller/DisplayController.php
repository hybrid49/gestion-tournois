<?php

namespace App\Controller;

use App\Entity\GameMatch;
use App\Enum\MatchPhase;
use App\Enum\MatchStatus;
use App\Enum\TournamentStatus;
use App\Repository\GameMatchRepository;
use App\Repository\TournamentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DisplayController extends AbstractController
{
    public function __construct(
        private readonly TournamentRepository $tournaments,
        private readonly GameMatchRepository $matches,
    ) {
    }

    #[Route('/api/public/tournaments/{id}/display', methods: ['GET'])]
    public function display(int $id): JsonResponse
    {
        $tournament = $this->tournaments->find($id);
        if (!$tournament) {
            return $this->json(['error' => 'Tournoi introuvable'], Response::HTTP_NOT_FOUND);
        }

        $current = $tournament->getCurrentMatchId()
            ? $this->matches->find($tournament->getCurrentMatchId())
            : null;
        $next = $tournament->getNextMatchId()
            ? $this->matches->find($tournament->getNextMatchId())
            : null;

        $allMatches = $tournament->getMatches()->toArray();
        $groupMatches = array_values(array_filter(
            $allMatches,
            static fn (GameMatch $m) => $m->getPhase() === MatchPhase::Group
        ));
        $tiebreakerMatches = array_values(array_filter(
            $allMatches,
            static fn (GameMatch $m) => $m->getPhase() === MatchPhase::Tiebreaker
        ));
        $bracketMatches = array_values(array_filter(
            $allMatches,
            static fn (GameMatch $m) => !in_array($m->getPhase(), [MatchPhase::Group, MatchPhase::Tiebreaker], true)
        ));

        $finalMatches = array_values(array_filter(
            $bracketMatches,
            static fn (GameMatch $m) => $m->getPhase() === MatchPhase::Final
        ));
        usort(
            $finalMatches,
            static fn (GameMatch $a, GameMatch $b) => [$a->getSortOrder(), $a->getSlot(), $a->getId()]
                <=> [$b->getSortOrder(), $b->getSlot(), $b->getId()]
        );
        $finalsComplete = [] !== $finalMatches;
        foreach ($finalMatches as $finalMatch) {
            if ($finalMatch->getStatus() !== MatchStatus::Done || null === $finalMatch->getWinner()) {
                $finalsComplete = false;
                break;
            }
        }
        $champion = null;
        if ($finalsComplete || $tournament->getStatus() === TournamentStatus::Finished) {
            $lastFinal = [] !== $finalMatches ? $finalMatches[array_key_last($finalMatches)] : null;
            $champion = $lastFinal?->getWinner()?->toArray();
        }

        return $this->json([
            'tournament' => [
                'id' => $tournament->getId(),
                'name' => $tournament->getName(),
                'status' => $tournament->getStatus()->value,
                'hasGroupStage' => $tournament->hasGroupStage(),
                'bracketType' => $tournament->getBracketType()->value,
                'teamMode' => $tournament->getTeamMode()->value,
            ],
            'champion' => $champion,
            'finished' => $tournament->getStatus() === TournamentStatus::Finished || ($finalsComplete && null !== $champion),
            'currentMatch' => $current?->toArray(),
            'nextMatch' => $next?->toArray(),
            'groups' => array_map(static fn ($g) => $g->toArray(), $tournament->getGroups()->toArray()),
            'groupMatches' => array_map(static fn (GameMatch $m) => $m->toArray(), $groupMatches),
            'tiebreakerMatches' => array_map(static fn (GameMatch $m) => $m->toArray(), $tiebreakerMatches),
            'bracketMatches' => array_map(static fn (GameMatch $m) => $m->toArray(), $bracketMatches),
        ]);
    }

    #[Route('/api/public/tournaments', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $all = $this->tournaments->findBy([], ['createdAt' => 'DESC']);

        return $this->json(array_map(static fn ($t) => [
            'id' => $t->getId(),
            'name' => $t->getName(),
            'status' => $t->getStatus()->value,
        ], $all));
    }
}
