<?php

declare(strict_types=1);

namespace App\Media\Access;

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
    ) {}

    public function canView(string $subject, string $mediaId): bool
    {
        if (!Uuid::isValid($mediaId)) {
            return false;
        }

        $media = $this->media->find(Uuid::fromString($mediaId));

        return null !== $media && $this->policy->canView($this->viewers->fromSubject($subject), $media);
    }
}
