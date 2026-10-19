<?php

declare(strict_types=1);

namespace App\Security\Guest;

use App\Guest\Entity\Guest;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Sicherheits-Identität eines Gastes (zustandslos, pro Anfrage aus dem Cookie aufgebaut).
 */
final readonly class GuestUser implements UserInterface
{
    public function __construct(
        public Guest $guest,
    ) {}

    public function getUserIdentifier(): string
    {
        return 'guest:' . $this->guest->getId()->toRfc4122();
    }

    public function getRoles(): array
    {
        return ['ROLE_GUEST'];
    }
}
