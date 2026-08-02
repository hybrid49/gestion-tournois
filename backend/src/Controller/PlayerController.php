<?php

namespace App\Controller;

use App\Entity\Player;
use App\Repository\PlayerRepository;
use App\Service\StatsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/players')]
class PlayerController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlayerRepository $players,
        private readonly StatsService $statsService,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));

        if ('' !== $q) {
            $all = $this->players->createQueryBuilder('p')
                ->where('LOWER(p.name) LIKE LOWER(:q)')
                ->setParameter('q', '%'.$q.'%')
                ->orderBy('p.name', 'ASC')
                ->setMaxResults(20)
                ->getQuery()
                ->getResult();
        } else {
            $all = $this->players->findBy([], ['name' => 'ASC'], 100);
        }

        return $this->json(array_map(static fn (Player $p) => $p->toArray(), $all));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $name = trim((string) ($data['name'] ?? ''));
        if ('' === $name) {
            return $this->json(['error' => 'Nom requis'], Response::HTTP_BAD_REQUEST);
        }

        $player = (new Player())->setName($name);
        $this->em->persist($player);
        $this->em->flush();

        return $this->json($player->toArray(), Response::HTTP_CREATED);
    }

    #[Route('/reset-history', methods: ['POST'])]
    public function resetHistory(): JsonResponse
    {
        $result = $this->statsService->clearAllHistory();

        return $this->json([
            'ok' => true,
            ...$result,
        ]);
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $player = $this->players->find($id);
        if (!$player) {
            return $this->json(['error' => 'Joueur introuvable'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($player->toArray());
    }

    #[Route('/{id}/stats', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function stats(int $id): JsonResponse
    {
        $player = $this->players->find($id);
        if (!$player) {
            return $this->json(['error' => 'Joueur introuvable'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->statsService->playerStats($player));
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $player = $this->players->find($id);
        if (!$player) {
            return $this->json(['error' => 'Joueur introuvable'], Response::HTTP_NOT_FOUND);
        }
        $this->em->remove($player);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }
}
