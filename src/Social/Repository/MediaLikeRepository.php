<?php

declare(strict_types=1);

namespace App\Social\Repository;

use App\Social\Entity\MediaLike;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MediaLike>
 */
class MediaLikeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MediaLike::class);
    }
}
