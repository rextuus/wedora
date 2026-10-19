<?php

declare(strict_types=1);

namespace App\Moderation;

use App\Guestbook\Entity\GuestbookEntry;
use App\Guestbook\Repository\GuestbookEntryRepository;
use App\Media\Entity\Media;
use App\Media\PublicationStatus;
use App\Media\Repository\MediaRepository;
use App\Media\Visibility;
use App\Social\Entity\MediaReport;
use App\Social\Repository\MediaReportRepository;
use App\Wedding\Entity\Wedding;
use App\Wedding\ModerationMode;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Arbeitslisten der Moderation. „Nur für mich“ ist der Moderation entzogen.
 */
final readonly class ModerationQueue
{
    public function __construct(
        private MediaRepository $media,
        private GuestbookEntryRepository $entries,
        private MediaReportRepository $reports,
    ) {}

    /**
     * Wartet auf Freigabe.
     *
     * @return list<Media|GuestbookEntry>
     */
    public function pending(Wedding $wedding): array
    {
        return $this->combined($wedding, 'pending');
    }

    /**
     * Sofort veröffentlicht, aber noch nicht gesichtet (Modus „nachträgliche Moderation“).
     *
     * @return list<Media|GuestbookEntry>
     */
    public function unreviewed(Wedding $wedding): array
    {
        return (
            ModerationMode::PostModeration === $wedding->getModerationMode()
                ? $this->combined($wedding, 'unreviewed')
                : []
        );
    }

    /**
     * @return list<MediaReport>
     */
    public function openReports(Wedding $wedding): array
    {
        /** @var list<MediaReport> */
        return $this->reports
            ->createQueryBuilder('r')
            ->join('r.media', 'm')
            ->where('m.wedding = :wedding')
            ->andWhere('r.resolvedAt IS NULL')
            ->andWhere('m.deletedAt IS NULL')
            ->orderBy('r.id', 'ASC')
            ->setParameter('wedding', $wedding->getId(), UuidType::NAME)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{pending: int, unreviewed: int, reports: int}
     */
    public function counts(Wedding $wedding): array
    {
        return [
            'pending' => count($this->pending($wedding)),
            'unreviewed' => count($this->unreviewed($wedding)),
            'reports' => count($this->openReports($wedding)),
        ];
    }

    /**
     * @return list<Media|GuestbookEntry>
     */
    private function combined(Wedding $wedding, string $kind): array
    {
        $mediaQb = $this->media
            ->createQueryBuilder('m')
            ->where('m.wedding = :wedding')
            ->andWhere('m.deletedAt IS NULL')
            ->andWhere('m.visibility != :private')
            ->setParameter('wedding', $wedding->getId(), UuidType::NAME)
            ->setParameter('private', Visibility::UploaderOnly);
        $entryQb = $this->entries
            ->createQueryBuilder('e')
            ->join('e.author', 'a')
            ->where('a.wedding = :wedding')
            ->andWhere('e.deletedAt IS NULL')
            ->setParameter('wedding', $wedding->getId(), UuidType::NAME);

        foreach ([[$mediaQb, 'm'], [$entryQb, 'e']] as [$qb, $alias]) {
            if ('pending' === $kind) {
                $qb->andWhere("{$alias}.publicationStatus = :status")->setParameter(
                    'status',
                    PublicationStatus::Pending,
                );
            } else {
                $qb
                    ->andWhere("{$alias}.publicationStatus = :status")
                    ->andWhere("{$alias}.reviewedAt IS NULL")
                    ->setParameter('status', PublicationStatus::Published);
            }

            $qb->orderBy("{$alias}.id", 'ASC')->setMaxResults(100);
        }

        /** @var list<Media> $media */
        $media = $mediaQb->getQuery()->getResult();
        /** @var list<GuestbookEntry> $entries */
        $entries = $entryQb->getQuery()->getResult();
        $items = [...$media, ...$entries];
        usort($items, static fn(Media|GuestbookEntry $a, Media|GuestbookEntry $b): int => strcmp(
            $a->getId()->toRfc4122(),
            $b->getId()->toRfc4122(),
        ));

        return $items;
    }
}
