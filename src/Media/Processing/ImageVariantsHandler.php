<?php

declare(strict_types=1);

namespace App\Media\Processing;

use App\Media\Image\ImageVariants;
use App\Media\Repository\MediaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Berechnet die Varianten im Worker. Schlägt das fehl, wiederholt Messenger; bis dahin rechnet imgproxy beim Abruf.
 */
#[AsMessageHandler]
final readonly class ImageVariantsHandler
{
    public function __construct(
        private MediaRepository $media,
        private ImageVariants $variants,
        private EntityManagerInterface $em,
    ) {}

    public function __invoke(GenerateImageVariants $message): void
    {
        $media = Uuid::isValid($message->mediaId) ? $this->media->find(Uuid::fromString($message->mediaId)) : null;
        if (null === $media || $media->isDeleted() || !$media->isReady()) {
            return;
        }

        $this->variants->generate($media->mediaId());
        $media->derivedChanged($this->variants->derivedBytes($media->mediaId()));
        $this->em->flush();
    }
}
