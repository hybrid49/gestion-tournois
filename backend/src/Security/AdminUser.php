<?php

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

class AdminUser implements UserInterface
{
    public function __construct(private readonly string $username)
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function getRoles(): array
    {
        return ['ROLE_ADMIN'];
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }
}
