<?php

namespace App\Controller;

use App\Entity\Player;
use App\Entity\Team;
use App\Entity\Tournament;
use App\Enum\BracketType;
use App\Enum\TeamMode;
use App\Enum\TournamentStatus;
use App\Repository\PlayerRepository;
use App\Repository\TournamentRepository;
use App\Service\BracketGeneratorService;
use App\Service\DrawService;
use App\Service\GroupStageService;
use App\Service\MatchResultService;
use App\Service\StatsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/tournaments')]
class TournamentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TournamentRepository $tournaments,
        private readonly PlayerRepository $players,
        private readonly DrawService $drawService,
        private readonly GroupStageService $groupStageService,
        private readonly BracketGeneratorService $bracketGeneratorService,
        private readonly MatchResultService $matchResultService,
        private readonly StatsService $statsService,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $all = $this->tournaments->findBy([], ['createdAt' => 'DESC']);

        return $this->json(array_map(static fn (Tournament $t) => $t->toArray(), $all));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $name = trim((string) ($data['name'] ?? ''));
        if ('' === $name) {
            return $this->json(['error' => 'Nom requis'], Response::HTTP_BAD_REQUEST);
        }

        $tournament = (new Tournament())
            ->setName($name)
            ->setHasGroupStage((bool) ($data['hasGroupStage'] ?? true))
            ->setBracketType(BracketType::from((string) ($data['bracketType'] ?? 'single')))
            ->setTeamMode(TeamMode::from((string) ($data['teamMode'] ?? 'solo')))
            ->setGroupCount(max(1, (int) ($data['groupCount'] ?? 2)))
            ->setQualifiersPerGroup(max(1, (int) ($data['qualifiersPerGroup'] ?? 2)))
            ->setStatus(TournamentStatus::Registration);

        $this->em->persist($tournament);
        $this->em->flush();

        return $this->json($this->serializeTournament($tournament), Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $tournament = $this->tournaments->find($id);
        if (!$tournament) {
            return $this->json(['error' => 'Tournoi introuvable'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->serializeTournament($tournament));
    }

    #[Route('/{id}', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $tournament = $this->tournaments->find($id);
        if (!$tournament) {
            return $this->json(['error' => 'Tournoi introuvable'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        if (isset($data['name'])) {
            $tournament->setName(trim((string) $data['name']));
        }
        if (isset($data['hasGroupStage'])) {
            $tournament->setHasGroupStage((bool) $data['hasGroupStage']);
        }
        if (isset($data['bracketType'])) {
            $tournament->setBracketType(BracketType::from((string) $data['bracketType']));
        }
        if (isset($data['teamMode'])) {
            $tournament->setTeamMode(TeamMode::from((string) $data['teamMode']));
        }
        if (isset($data['groupCount'])) {
            $tournament->setGroupCount(max(1, (int) $data['groupCount']));
        }
        if (isset($data['qualifiersPerGroup'])) {
            $tournament->setQualifiersPerGroup(max(1, (int) $data['qualifiersPerGroup']));
        }
        if (isset($data['status'])) {
            $tournament->setStatus(TournamentStatus::from((string) $data['status']));
        }

        $this->em->flush();

        return $this->json($this->serializeTournament($tournament));
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $tournament = $this->tournaments->find($id);
        if (!$tournament) {
            return $this->json(['error' => 'Tournoi introuvable'], Response::HTTP_NOT_FOUND);
        }
        $this->em->remove($tournament);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/{id}/register', methods: ['POST'])]
    public function register(int $id, Request $request): JsonResponse
    {
        $tournament = $this->tournaments->find($id);
        if (!$tournament) {
            return $this->json(['error' => 'Tournoi introuvable'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        try {
            if ($tournament->getTeamMode() === TeamMode::Duo) {
                $p1 = $this->resolvePlayer($data['player1Id'] ?? null, $data['player1Name'] ?? null);
                $p2 = $this->resolvePlayer($data['player2Id'] ?? null, $data['player2Name'] ?? null);
                if (!$p1 || !$p2) {
                    return $this->json(['error' => 'Deux joueurs requis en mode duo'], Response::HTTP_BAD_REQUEST);
                }
                if ($p1->getId() === $p2->getId()) {
                    return $this->json(['error' => 'Les deux joueurs du duo doivent être différents'], Response::HTTP_BAD_REQUEST);
                }
                $this->assertPlayerNotAlreadyRegistered($tournament, $p1);
                $this->assertPlayerNotAlreadyRegistered($tournament, $p2);
                $name = trim((string) ($data['name'] ?? ($p1->getName().' / '.$p2->getName())));
                $team = (new Team())
                    ->setTournament($tournament)
                    ->setPlayer1($p1)
                    ->setPlayer2($p2)
                    ->setName($name);
            } else {
                $p1 = $this->resolvePlayer($data['playerId'] ?? $data['player1Id'] ?? null, $data['playerName'] ?? $data['name'] ?? null);
                if (!$p1) {
                    return $this->json(['error' => 'Joueur requis'], Response::HTTP_BAD_REQUEST);
                }
                $this->assertPlayerNotAlreadyRegistered($tournament, $p1);
                $team = (new Team())
                    ->setTournament($tournament)
                    ->setPlayer1($p1)
                    ->setName(trim((string) ($data['teamName'] ?? $p1->getName())));
            }

            $tournament->addTeam($team);
            $this->em->persist($team);
            $this->statsService->markTournamentParticipation($team);
            $this->em->flush();

            return $this->json($team->toArray(), Response::HTTP_CREATED);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}/teams/{teamId}', methods: ['DELETE'])]
    public function unregister(int $id, int $teamId): JsonResponse
    {
        $tournament = $this->tournaments->find($id);
        if (!$tournament) {
            return $this->json(['error' => 'Tournoi introuvable'], Response::HTTP_NOT_FOUND);
        }

        if ($tournament->getMatches()->count() > 0) {
            return $this->json([
                'error' => 'Impossible de retirer une inscription : des matchs ont déjà été générés. Régénérez les poules/bracket après modification, ou créez un nouveau tournoi.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $team = null;
        foreach ($tournament->getTeams() as $candidate) {
            if ($candidate->getId() === $teamId) {
                $team = $candidate;
                break;
            }
        }
        if (!$team) {
            return $this->json(['error' => 'Inscription introuvable'], Response::HTTP_NOT_FOUND);
        }

        $this->statsService->unmarkTournamentParticipation($team);
        $tournament->getTeams()->removeElement($team);
        $this->em->remove($team);
        $this->em->flush();

        return $this->json($this->serializeTournament($tournament));
    }

    #[Route('/{id}/register-players', methods: ['POST'])]
    public function registerPlayersForDuoDraw(int $id, Request $request): JsonResponse
    {
        $tournament = $this->tournaments->find($id);
        if (!$tournament) {
            return $this->json(['error' => 'Tournoi introuvable'], Response::HTTP_NOT_FOUND);
        }
        if ($tournament->getTeamMode() !== TeamMode::Duo) {
            return $this->json(['error' => 'Réservé au mode duo'], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $names = $data['names'] ?? [];
        if (!is_array($names) || count($names) < 2) {
            return $this->json(['error' => 'Liste de noms requise'], Response::HTTP_BAD_REQUEST);
        }

        $playerEntities = [];
        foreach ($names as $name) {
            $player = $this->resolvePlayer(null, $name);
            if (!$player) {
                continue;
            }
            $this->assertPlayerNotAlreadyRegistered($tournament, $player);
            // éviter les doublons dans la liste saisie
            foreach ($playerEntities as $already) {
                if ($already->getId() === $player->getId()) {
                    throw new \InvalidArgumentException(sprintf('%s apparaît plusieurs fois.', $player->getName()));
                }
            }
            $playerEntities[] = $player;
        }

        try {
            $teams = $this->drawService->formRandomDuos($tournament, $playerEntities);
            foreach ($teams as $team) {
                $this->statsService->markTournamentParticipation($team);
            }

            return $this->json([
                'teams' => array_map(static fn (Team $t) => $t->toArray(), $teams),
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}/draw', methods: ['POST'])]
    public function draw(int $id): JsonResponse
    {
        $tournament = $this->tournaments->find($id);
        if (!$tournament) {
            return $this->json(['error' => 'Tournoi introuvable'], Response::HTTP_NOT_FOUND);
        }

        try {
            $teams = $this->drawService->drawTournament($tournament);

            return $this->json([
                'teams' => array_map(static fn (Team $t) => $t->toArray(), $teams),
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}/generate-groups', methods: ['POST'])]
    public function generateGroups(int $id): JsonResponse
    {
        $tournament = $this->tournaments->find($id);
        if (!$tournament) {
            return $this->json(['error' => 'Tournoi introuvable'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->groupStageService->generate($tournament);

            return $this->json($this->serializeTournament($tournament));
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}/tiebreakers', methods: ['POST'])]
    public function createTiebreakers(int $id, Request $request): JsonResponse
    {
        $tournament = $this->tournaments->find($id);
        if (!$tournament) {
            return $this->json(['error' => 'Tournoi introuvable'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $groupId = (int) ($data['groupId'] ?? 0);
        $mode = (string) ($data['mode'] ?? 'duel');

        try {
            $this->groupStageService->createTiebreakers($tournament, $groupId, $mode);

            return $this->json($this->serializeTournament($tournament));
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}/generate-bracket', methods: ['POST'])]
    public function generateBracket(int $id): JsonResponse
    {
        $tournament = $this->tournaments->find($id);
        if (!$tournament) {
            return $this->json(['error' => 'Tournoi introuvable'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->bracketGeneratorService->generate($tournament);

            return $this->json($this->serializeTournament($tournament));
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}/resync-bracket', methods: ['POST'])]
    public function resyncBracket(int $id): JsonResponse
    {
        $tournament = $this->tournaments->find($id);
        if (!$tournament) {
            return $this->json(['error' => 'Tournoi introuvable'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->matchResultService->resyncBracket($tournament);

            return $this->json($this->serializeTournament($tournament));
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}/rebuild-bracket', methods: ['POST'])]
    public function rebuildBracket(int $id): JsonResponse
    {
        $tournament = $this->tournaments->find($id);
        if (!$tournament) {
            return $this->json(['error' => 'Tournoi introuvable'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->matchResultService->rebuildBracketPreservingResults($tournament);

            return $this->json($this->serializeTournament($tournament));
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}/display', methods: ['PATCH'])]
    public function updateDisplay(int $id, Request $request): JsonResponse
    {
        $tournament = $this->tournaments->find($id);
        if (!$tournament) {
            return $this->json(['error' => 'Tournoi introuvable'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        try {
            $this->matchResultService->setDisplay(
                $tournament,
                isset($data['currentMatchId']) ? (int) $data['currentMatchId'] : null,
                isset($data['nextMatchId']) ? (int) $data['nextMatchId'] : null,
            );

            return $this->json($this->serializeTournament($tournament));
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    private function serializeTournament(Tournament $tournament): array
    {
        $data = $tournament->toArray(true);
        $data['groupStage'] = $this->groupStageService->getStageSummary($tournament);

        $editableIds = [];
        foreach ($tournament->getMatches() as $match) {
            if ($this->matchResultService->canCorrect($match)) {
                $editableIds[$match->getId()] = true;
            }
        }
        $data['matches'] = array_map(static function (array $row) use ($editableIds): array {
            $row['editable'] = isset($editableIds[$row['id']]);

            return $row;
        }, $data['matches'] ?? []);

        return $data;
    }

    private function resolvePlayer(mixed $id, mixed $name): ?Player
    {
        if ($id) {
            return $this->players->find((int) $id);
        }
        $name = $this->formatPlayerName((string) $name);
        if ('' === $name) {
            return null;
        }

        $existing = $this->players->createQueryBuilder('p')
            ->where('LOWER(p.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existing instanceof Player) {
            return $existing;
        }

        $player = (new Player())->setName($name);
        $this->em->persist($player);
        $this->em->flush();

        return $player;
    }

    private function formatPlayerName(string $name): string
    {
        $name = trim($name);
        if ('' === $name) {
            return '';
        }

        $parts = preg_split('/(\s+|-)/u', $name, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $formatted = '';
        foreach ($parts as $part) {
            if ('' === $part || preg_match('/^\s+$/u', $part) || '-' === $part) {
                $formatted .= $part;
                continue;
            }
            $first = mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8');
            $rest = mb_strtolower(mb_substr($part, 1, null, 'UTF-8'), 'UTF-8');
            $formatted .= $first.$rest;
        }

        return $formatted;
    }

    private function assertPlayerNotAlreadyRegistered(Tournament $tournament, Player $player): void
    {
        foreach ($tournament->getTeams() as $team) {
            foreach ($team->getPlayers() as $registered) {
                if ($registered->getId() === $player->getId()) {
                    throw new \InvalidArgumentException(sprintf(
                        '%s est déjà inscrit à ce tournoi.',
                        $player->getName()
                    ));
                }
            }
        }
    }
}
