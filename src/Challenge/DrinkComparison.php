<?php

declare(strict_types=1);

namespace App\Challenge;

use App\Challenge\Entity\Challenge;
use App\Guest\Entity\Guest;
use App\Media\Entity\Media;
use App\Media\MediaType;
use App\Media\ProcessingStatus;
use App\Media\PublicationStatus;
use App\Media\Repository\MediaRepository;
use App\Media\Visibility;
use SortDirection;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Vorher – nachher: First- und Last-Drink-Video eines Gastes nebeneinander.
 *
 * Die Paare der anderen sieht nur, wer selbst beide Videos gedreht hat. Für die anderen gelten
 * dieselben Bedingungen wie in der Galerie (für alle Gäste, veröffentlicht, fertig verarbeitet).
 */
final readonly class DrinkComparison
{
    public function __construct(
        private WelcomeMission $mission,
        private MediaRepository $media,
    ) {}

    /**
     * @return array{Challenge, Challenge}|null [First Drink, Last Drink], wenn es beide Aufgaben gibt
     */
    public function challenges(Guest $guest): ?array
    {
        return $this->mission->drinkPair($guest->getWedding());
    }

    /**
     * Eigene Videos (auch privat oder noch in Verarbeitung; abgelehnte und gelöschte zählen nicht).
     *
     * @return array{first: Media|null, last: Media|null}
     */
    public function own(Guest $guest): array
    {
        $pair = $this->challenges($guest);
        if (null === $pair) {
            return ['first' => null, 'last' => null];
        }

        /** @var list<Media> $videos */
        $videos = $this->media
            ->createQueryBuilder('m')
            ->where('m.uploaderGuest = :guest')
            ->andWhere('m.challenge IN (:challenges)')
            ->andWhere('m.type = :video')
            ->andWhere('m.deletedAt IS NULL')
            ->andWhere('m.publicationStatus != :rejected')
            ->orderBy('m.id', SortDirection::Descending)
            ->setParameter('guest', $guest->getId(), UuidType::NAME)
            ->setParameter('challenges', [$pair[0]->getId()->toBinary(), $pair[1]->getId()->toBinary()])
            ->setParameter('video', MediaType::Video)
            ->setParameter('rejected', PublicationStatus::Rejected)
            ->getQuery()
            ->getResult();

        return (
            self::latestPerChallenge($videos, $pair)[$guest->getId()->toRfc4122()] ?? ['first' => null, 'last' => null]
        );
    }

    public function isUnlockedFor(Guest $guest): bool
    {
        $own = $this->own($guest);

        return null !== $own['first'] && null !== $own['last'];
    }

    /**
     * Vollständige Paare der anderen Gäste, neueste zuerst – leer, solange der Gast selbst keins hat.
     *
     * @return list<array{guest: Guest, first: Media, last: Media}>
     */
    public function others(Guest $guest): array
    {
        $pair = $this->challenges($guest);
        if (null === $pair || !$this->isUnlockedFor($guest)) {
            return [];
        }

        /** @var list<Media> $videos */
        $videos = $this->media
            ->createQueryBuilder('m')
            ->where('m.wedding = :wedding')
            ->andWhere('m.challenge IN (:challenges)')
            ->andWhere('m.type = :video')
            ->andWhere('m.uploaderGuest IS NOT NULL')
            ->andWhere('m.uploaderGuest != :guest')
            ->andWhere('m.deletedAt IS NULL')
            ->andWhere('m.visibility = :allGuests')
            ->andWhere('m.publicationStatus = :published')
            ->andWhere('m.processingStatus = :ready')
            ->orderBy('m.id', SortDirection::Descending)
            ->setParameter('wedding', $guest->getWedding()->getId(), UuidType::NAME)
            ->setParameter('guest', $guest->getId(), UuidType::NAME)
            ->setParameter('challenges', [$pair[0]->getId()->toBinary(), $pair[1]->getId()->toBinary()])
            ->setParameter('video', MediaType::Video)
            ->setParameter('allGuests', Visibility::AllGuests)
            ->setParameter('published', PublicationStatus::Published)
            ->setParameter('ready', ProcessingStatus::Ready)
            ->getQuery()
            ->getResult();

        $pairs = [];
        foreach (self::latestPerChallenge($videos, $pair) as $entry) {
            $owner = $entry['first']?->getUploaderGuest();
            if (null !== $entry['first'] && null !== $entry['last'] && null !== $owner) {
                $pairs[] = ['guest' => $owner, 'first' => $entry['first'], 'last' => $entry['last']];
            }
        }

        return $pairs;
    }

    /**
     * @param list<Media>                  $videos neueste zuerst
     * @param array{Challenge, Challenge} $pair
     *
     * @return array<string, array{first: Media|null, last: Media|null}> je Gast-ID
     */
    private static function latestPerChallenge(array $videos, array $pair): array
    {
        $byGuest = [];
        foreach ($videos as $video) {
            $guestId = $video->getUploaderGuest()?->getId()->toRfc4122();
            if (null === $guestId) {
                continue;
            }

            $slot = $video->getChallenge() === $pair[0] ? 'first' : 'last';
            $byGuest[$guestId] ??= ['first' => null, 'last' => null];
            $byGuest[$guestId][$slot] ??= $video;
        }

        return $byGuest;
    }
}
