<?php

declare(strict_types=1);

namespace App\Security;

use App\Account\Entity\User;
use App\Account\Repository\UserRepository;
use App\Account\Repository\WeddingMemberRepository;
use App\Guest\Entity\Guest;
use App\Guest\Repository\GuestRepository;
use App\Security\Guest\GuestUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ViewerResolver
{
    public function __construct(
        private GuestRepository $guests,
        private UserRepository $users,
        private WeddingMemberRepository $members,
    ) {}

    public function fromSecurityUser(?UserInterface $user): Viewer
    {
        return match (true) {
            $user instanceof GuestUser => self::fromGuest($user->guest),
            $user instanceof User => $this->fromAccount($user),
            default => Viewer::anonymous(),
        };
    }

    /**
     * Für signierte Medien-URLs: lädt die Identität frisch, damit Sperren/Widerrufe greifen.
     */
    public function fromSubject(string $subject): Viewer
    {
        [$kind, $id] = array_pad(explode(':', $subject, 2), 2, '');
        if (!Uuid::isValid($id)) {
            return Viewer::anonymous();
        }

        $uuid = Uuid::fromString($id);

        return match ($kind) {
            'guest' => self::fromGuest($this->guests->find($uuid)),
            'user' => $this->fromAccount($this->users->find($uuid)),
            default => Viewer::anonymous(),
        };
    }

    private static function fromGuest(?Guest $guest): Viewer
    {
        if (null === $guest || !$guest->hasAccess()) {
            return Viewer::anonymous();
        }

        return Viewer::guest($guest->getId(), $guest->getWedding()->getId());
    }

    private function fromAccount(?User $user): Viewer
    {
        if (null === $user) {
            return Viewer::anonymous();
        }

        return Viewer::user($user->getId(), $user->isPlatformAdmin(), $this->members->permissionsByWedding($user));
    }
}
