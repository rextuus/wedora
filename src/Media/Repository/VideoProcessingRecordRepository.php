<?php

declare(strict_types=1);

namespace App\Media\Repository;

use App\Media\Entity\VideoProcessingRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VideoProcessingRecord>
 */
class VideoProcessingRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VideoProcessingRecord::class);
    }

    /**
     * Jüngste erfolgreiche Umwandlungen (neueste zuerst) – ältere Messwerte verlieren
     * an Aussagekraft, wenn sich Server oder Einstellungen ändern.
     *
     * @return list<VideoProcessingRecord>
     */
    public function recentSuccessful(int $limit): array
    {
        return $this->findBy(['succeeded' => true], ['createdAt' => 'DESC'], $limit);
    }
}
