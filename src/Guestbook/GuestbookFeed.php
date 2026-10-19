<?php

declare(strict_types=1);

namespace App\Guestbook;

use App\Gallery\GalleryQuery;
use App\Guest\Entity\Guest;
use App\Guestbook\Entity\GuestbookEntry;
use App\Guestbook\Repository\GuestbookEntryRepository;
use App\Media\Entity\Media;
use App\Media\PublicationStatus;
use App\Media\Visibility;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Gästebuch für Gäste: Texte und Foto-/Video-Grüße, neueste zuerst.
 * Eigene Einträge erscheinen immer (mit Status), fremde nur „für alle Gäste“ und veröffentlicht.
 */
final readonly class GuestbookFeed
{
    public function __construct(
        private GuestbookEntryRepository $entries,
        private GalleryQuery $gallery,
    ) {}

    /**
     * @phpstan-impure liest den aktuellen Datenbankstand
     *
     * @return list<GuestbookEntry|Media>
     */
    public function forGuest(Guest $guest, int $limit = 60): array
    {
        /** @var list<GuestbookEntry> $entries */
        $entries = $this->entries
            ->createQueryBuilder('e')
            ->join('e.author', 'a')
            ->where('a.wedding = :wedding')
            ->andWhere('e.deletedAt IS NULL')
            ->andWhere('e.author = :guest OR (e.visibility = :allGuests AND e.publicationStatus = :published)')
            ->orderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('wedding', $guest->getWedding()->getId(), UuidType::NAME)
            ->setParameter('guest', $guest->getId(), UuidType::NAME)
            ->setParameter('allGuests', Visibility::AllGuests)
            ->setParameter('published', PublicationStatus::Published)
            ->getQuery()
            ->getResult();

        $items = [...$entries, ...$this->gallery->guestbookMedia($guest, $limit)];
        usort($items, static fn(GuestbookEntry|Media $a, GuestbookEntry|Media $b): int => strcmp(
            $b->getId()->toRfc4122(),
            $a->getId()->toRfc4122(),
        ));

        return array_slice($items, 0, $limit);
    }
}
