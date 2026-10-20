<?php

declare(strict_types=1);

namespace App\Gallery;

use App\Challenge\Entity\Challenge;
use App\Guest\Entity\Guest;
use App\Media\Entity\Media;
use App\Media\MediaType;
use App\Media\ProcessingStatus;
use App\Media\PublicationStatus;
use App\Media\Repository\MediaRepository;
use App\Media\Visibility;
use App\Wedding\ModuleType;
use Doctrine\ORM\QueryBuilder;
use SortDirection;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Listen für Gäste. Die Bedingungen entsprechen MediaAccessPolicy::canView für Gäste
 * (Tests prüfen, dass jede Liste nur Erlaubtes enthält).
 *
 * Geblättert wird über die zeitlich geordnete ID (UUIDv7), neueste zuerst.
 */
final readonly class GalleryQuery
{
    public const int PAGE_SIZE = 24;

    public function __construct(
        private MediaRepository $media,
    ) {}

    /**
     * Gemeinsames Album: für alle Gäste sichtbar, veröffentlicht, fertig verarbeitet.
     *
     * @phpstan-impure liest den aktuellen Datenbankstand
     *
     * @return list<Media>
     */
    public function shared(
        Guest $guest,
        GalleryFilter $filter = GalleryFilter::All,
        ?Uuid $before = null,
        int $limit = self::PAGE_SIZE,
    ): array {
        $qb = $this
            ->base($guest, $before, $limit)
            ->andWhere('m.visibility = :allGuests')
            ->andWhere('m.publicationStatus = :published')
            ->andWhere('m.processingStatus = :ready')
            ->setParameter('allGuests', Visibility::AllGuests)
            ->setParameter('published', PublicationStatus::Published)
            ->setParameter('ready', ProcessingStatus::Ready);

        match ($filter) {
            GalleryFilter::Photos => $qb->andWhere('m.type = :type')->setParameter('type', MediaType::Photo),
            GalleryFilter::Videos => $qb->andWhere('m.type = :type')->setParameter('type', MediaType::Video),
            GalleryFilter::Guestbook => $qb->andWhere('m.module = :module')->setParameter(
                'module',
                ModuleType::Guestbook,
            ),
            GalleryFilter::All => null,
        };

        return self::result($qb);
    }

    /**
     * Eigene Beiträge inkl. unveröffentlichter, privater und in Verarbeitung.
     *
     * @phpstan-impure liest den aktuellen Datenbankstand
     *
     * @return list<Media>
     */
    public function mine(Guest $guest, ?Uuid $before = null, int $limit = 50): array
    {
        $qb = $this
            ->base($guest, $before, $limit)
            ->andWhere('m.uploaderGuest = :guest')
            ->setParameter('guest', $guest->getId(), UuidType::NAME);

        return self::result($qb);
    }

    /**
     * Eigene Beiträge zu einer Aufgabe (wie mine(), z. B. das eigene First-Drink-Video).
     *
     * @phpstan-impure liest den aktuellen Datenbankstand
     *
     * @return list<Media>
     */
    public function mineFor(Guest $guest, Challenge $challenge, int $limit = 24): array
    {
        $qb = $this
            ->base($guest, null, $limit)
            ->andWhere('m.uploaderGuest = :guest')
            ->andWhere('m.challenge = :challenge')
            ->setParameter('guest', $guest->getId(), UuidType::NAME)
            ->setParameter('challenge', $challenge->getId(), UuidType::NAME);

        return self::result($qb);
    }

    /**
     * Für das Gästebuch: Foto-/Video-Grüße, die der Gast sehen darf.
     *
     * @phpstan-impure liest den aktuellen Datenbankstand
     *
     * @return list<Media>
     */
    public function guestbookMedia(Guest $guest, int $limit = 50): array
    {
        return $this->shared($guest, GalleryFilter::Guestbook, null, $limit);
    }

    private function base(Guest $guest, ?Uuid $before, int $limit): QueryBuilder
    {
        $qb = $this->media
            ->createQueryBuilder('m')
            // Kacheln zeigen Name/Avatar und ggf. die Aufgabe: gleich mitladen statt je Kachel nachzufragen.
            ->leftJoin('m.uploaderGuest', 'g')
            ->leftJoin('m.challenge', 'c')
            ->addSelect('g', 'c')
            ->where('m.wedding = :wedding')
            ->andWhere('m.deletedAt IS NULL')
            ->orderBy('m.id', SortDirection::Descending)
            ->setMaxResults($limit)
            ->setParameter('wedding', $guest->getWedding()->getId(), UuidType::NAME);

        if (null !== $before) {
            $qb->andWhere('m.id < :before')->setParameter('before', $before, UuidType::NAME);
        }

        return $qb;
    }

    /**
     * @return list<Media>
     */
    private static function result(QueryBuilder $qb): array
    {
        /** @var list<Media> */
        return $qb->getQuery()->getResult();
    }
}
