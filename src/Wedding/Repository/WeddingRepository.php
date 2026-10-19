<?php

declare(strict_types=1);

namespace App\Wedding\Repository;

use App\Wedding\Entity\Wedding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Wedding>
 */
class WeddingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Wedding::class);
    }

    public function findOneBySlug(string $slug): ?Wedding
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
