<?php

namespace App\Controller;

use App\Entity\Team;
use App\Repository\GameMatchRepository;
use App\Repository\TeamRepository;
use App\Service\MatchResultService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/matches')]
class MatchController extends AbstractController
{
    public function __construct(
        private readonly GameMatchRepository $matches,
        private readonly TeamRepository $teams,
        private readonly MatchResultService $matchResultService,
    ) {
    }

    #[Route('/{id}/result', methods: ['POST'])]
    public function result(int $id, Request $request): JsonResponse
    {
        $match = $this->matches->find($id);
        if (!$match) {
            return $this->json(['error' => 'Match introuvable'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $winnerId = (int) ($data['winnerId'] ?? 0);
        /** @var Team|null $winner */
        $winner = $this->teams->find($winnerId);
        if (!$winner) {
            return $this->json(['error' => 'Vainqueur requis'], Response::HTTP_BAD_REQUEST);
        }

        $scoreHome = array_key_exists('scoreHome', $data) && null !== $data['scoreHome']
            ? (int) $data['scoreHome'] : null;
        $scoreAway = array_key_exists('scoreAway', $data) && null !== $data['scoreAway']
            ? (int) $data['scoreAway'] : null;

        try {
            $this->matchResultService->setResult($match, $winner, $scoreHome, $scoreAway);

            return $this->json($match->toArray());
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
