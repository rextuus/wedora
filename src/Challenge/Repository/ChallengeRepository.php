<?php

declare(strict_types=1);

namespace App\Challenge\Repository;

use App\Challenge\Entity\Challenge;
use App\Wedding\Entity\Wedding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Challenge>
 */
class ChallengeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Challenge::class);
    }

    /**
     * @return list<Challenge>
     */
    public function forWedding(Wedding $wedding, bool $onlyEnabled = false): array
    {
        $criteria = ['wedding' => $wedding];
        if ($onlyEnabled) {
            $criteria['enabled'] = true;
        }

        return $this->findBy($criteria, ['position' => 'ASC', 'createdAt' => 'ASC']);
    }
}
