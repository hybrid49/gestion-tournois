<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class AdminTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        #[Autowire('%env(ADMIN_USER)%')]
        private readonly string $adminUser,
        #[Autowire('%env(ADMIN_PASSWORD)%')]
        private readonly string $adminPassword,
        #[Autowire('%env(APP_SECRET)%')]
        private readonly string $appSecret,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return str_starts_with($request->getPathInfo(), '/api')
            && !str_starts_with($request->getPathInfo(), '/api/login')
            && !str_starts_with($request->getPathInfo(), '/api/public');
    }

    public function authenticate(Request $request): Passport
    {
        $token = $request->headers->get('X-Admin-Token')
            ?? $request->headers->get('Authorization');

        if ($token && str_starts_with($token, 'Bearer ')) {
            $token = substr($token, 7);
        }

        if (!$token || !hash_equals($this->expectedToken(), $token)) {
            throw new AuthenticationException('Token admin invalide.');
        }

        return new SelfValidatingPassport(new UserBadge($this->adminUser, fn () => new AdminUser($this->adminUser)));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => $exception->getMessageKey() ?: 'Non autorisé'], Response::HTTP_UNAUTHORIZED);
    }

    public function expectedToken(): string
    {
        return hash_hmac('sha256', $this->adminUser.':'.$this->adminPassword, $this->appSecret);
    }

    public function validateCredentials(string $username, string $password): bool
    {
        return hash_equals($this->adminUser, $username) && hash_equals($this->adminPassword, $password);
    }
}
