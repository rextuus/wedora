<?php

declare(strict_types=1);

namespace App\Challenge;

use App\Media\Entity\Media;
use App\Media\MediaDeleter;
use App\Media\Repository\MediaRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * First und Last Drink dreht jeder Gast nur einmal: Ein neues Video ersetzt das bisherige.
 * Das alte wird erst gelöscht (mit allen Dateien), wenn das neue vollständig und geprüft angekommen ist.
 */
final readonly class DrinkVideoReplacement
{
    public function __construct(
        private WelcomeMission $mission,
        private MediaRepository $media,
        private MediaDeleter $deleter,
    ) {}

    /** Löscht die früheren Beiträge des Gastes zur selben Drink-Aufgabe; liefert deren Anzahl. */
    public function replacePrevious(Media $new): int
    {
        $challenge = $new->getChallenge();
        $guest = $new->getUploaderGuest();
        if (null === $challenge || null === $guest || !$this->mission->isDrink($challenge)) {
            return 0;
        }

        /** @var list<Media> $previous */
        $previous = $this->media
            ->createQueryBuilder('m')
            ->where('m.uploaderGuest = :guest')
            ->andWhere('m.challenge = :challenge')
            ->andWhere('m.id != :new')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('guest', $guest->getId(), UuidType::NAME)
            ->setParameter('challenge', $challenge->getId(), UuidType::NAME)
            ->setParameter('new', $new->getId(), UuidType::NAME)
            ->getQuery()
            ->getResult();
        foreach ($previous as $old) {
            $this->deleter->delete($old);
        }

        return count($previous);
    }
}
