<?php

namespace App\Controller;

use App\Security\AdminTokenAuthenticator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AuthController extends AbstractController
{
    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function login(Request $request, AdminTokenAuthenticator $authenticator): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $username = (string) ($data['username'] ?? '');
        $password = (string) ($data['password'] ?? '');

        if (!$authenticator->validateCredentials($username, $password)) {
            return $this->json(['error' => 'Identifiants invalides'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'token' => $authenticator->expectedToken(),
            'username' => $username,
        ]);
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        return $this->json([
            'username' => $this->getUser()?->getUserIdentifier(),
            'roles' => $this->getUser()?->getRoles() ?? [],
        ]);
    }
}
