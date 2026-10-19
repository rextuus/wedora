<?php

declare(strict_types=1);

namespace App\Social\Repository;

use App\Social\Entity\MediaReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MediaReport>
 */
class MediaReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MediaReport::class);
    }
}
