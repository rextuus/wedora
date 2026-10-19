<?php

declare(strict_types=1);

namespace App\Media\Processing;

use App\Media\Entity\Media;
use App\Media\Entity\VideoProcessingRecord;
use App\Media\MediaType;
use App\Media\ProcessingStatus;
use App\Media\Repository\MediaRepository;
use App\Media\Repository\VideoProcessingRecordRepository;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Voraussichtliche Restzeit, bis ein Video fertig ist – inklusive der Videos, die vorher
 * in der Warteschlange stehen (verteilt auf die Anzahl der Worker).
 */
final readonly class ProcessingForecast
{
    /** So viele jüngste Messungen fließen ein; ältere spiegeln den Server oft nicht mehr wider. */
    private const int WINDOW = 300;

    public function __construct(
        private VideoProcessingRecordRepository $records,
        private MediaRepository $media,
        private ClockInterface $clock,
        #[Autowire('%env(int:VIDEO_WORKERS)%')]
        private int $workers,
    ) {}

    /**
     * @return int|null Sekunden; null = keine belastbare Schätzung; 0 = Schätzung überschritten
     */
    public function remainingSeconds(Media $media): ?int
    {
        if (MediaType::Video !== $media->getType() || ProcessingStatus::Processing !== $media->getProcessingStatus()) {
            return null;
        }

        $records = $this->records->recentSuccessful(self::WINDOW);
        $own = $this->estimate($records, $media);
        if (null === $own) {
            return null;
        }

        $ahead = 0.0;
        foreach ($this->queuedBefore($media) as $other) {
            $ahead += $this->estimate($records, $other) ?? $own;
        }

        $elapsed =
            DateTimeImmutable::createFromInterface($this->clock->now())->getTimestamp()
            - $media->getCreatedAt()->getTimestamp();
        $total = ($ahead / max(1, $this->workers)) + $own;

        return max(0, (int) ceil($total - $elapsed));
    }

    /**
     * @param list<VideoProcessingRecord> $records
     */
    private function estimate(array $records, Media $media): ?float
    {
        return ProcessingTimeEstimator::estimateSeconds(
            $records,
            $media->getDurationSeconds() ?? 0.0,
            $media->getWidth() ?? 0,
            $media->getHeight() ?? 0,
        );
    }

    /**
     * @return list<Media>
     */
    private function queuedBefore(Media $media): array
    {
        /** @var list<Media> */
        return $this->media
            ->createQueryBuilder('m')
            ->where('m.processingStatus = :processing')
            ->andWhere('m.type = :video')
            ->andWhere('m.deletedAt IS NULL')
            // UUIDv7 ist millisekundengenau zeitlich geordnet; created_at hat nur Sekunden.
            ->andWhere('m.id < :id')
            ->setParameter('processing', ProcessingStatus::Processing)
            ->setParameter('video', MediaType::Video)
            ->setParameter('id', $media->getId(), UuidType::NAME)
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();
    }
}
