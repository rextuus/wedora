<?php

declare(strict_types=1);

namespace App\Media;

use App\Media\Entity\Media;
use App\Media\Storage\MediaStorage;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Löscht ein Medium: Original, Varianten und Standbild sofort; der Datensatz bleibt
 * als „gelöscht“ für Nachvollziehbarkeit. Es gibt keinen Bild-Cache, der zu leeren wäre.
 */
final readonly class MediaDeleter
{
    public function __construct(
        private MediaStorage $storage,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {}

    public function delete(Media $media): void
    {
        $media->markDeleted(DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->storage->deleteAll($media->mediaId());
        $this->em->flush();
    }
}
