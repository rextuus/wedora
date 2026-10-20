<?php

declare(strict_types=1);

namespace App\Media\Access;

use App\Challenge\DrinkLock;
use App\Guest\Repository\GuestRepository;
use App\Media\Repository\MediaRepository;
use App\Security\MediaAccessPolicy;
use App\Security\ViewerResolver;
use Symfony\Component\Uid\Uuid;

/**
 * Prüft bei jedem Medienabruf mit frisch geladenem Betrachter und Medium,
 * damit Sperren, Widerrufe, Moderation und Sichtbarkeitsänderungen sofort wirken.
 */
final readonly class PolicyMediaAccessChecker implements MediaAccessChecker
{
    public function __construct(
        private ViewerResolver $viewers,
        private MediaRepository $media,
        private MediaAccessPolicy $policy,
        private GuestRepository $guests,
        private DrinkLock $drinkLock,
    ) {}

    public function canView(string $subject, string $mediaId, string $variant = 'image/display'): bool
    {
        if (!Uuid::isValid($mediaId)) {
            return false;
        }

        $media = $this->media->find(Uuid::fromString($mediaId));
        $viewer = $this->viewers->fromSubject($subject);
        if (null === $media || !$this->policy->canView($viewer, $media)) {
            return false;
        }

        // Gesperrte Drink-Videos: nur die verwischte Mini-Vorschau.
        $guest = null === $viewer->guestId ? null : $this->guests->find($viewer->guestId);

        return null === $guest
        || DrinkLock::PREVIEW_VARIANT === $variant
        || !$this->drinkLock->isLocked($guest, $media);
    }
}
