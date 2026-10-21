<?php

declare(strict_types=1);

namespace App\Challenge\Repository;

use App\Challenge\Entity\ChallengeDraw;
use App\Guest\Entity\Guest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<ChallengeDraw>
 */
class ChallengeDrawRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChallengeDraw::class);
    }

    /**
     * Gezogene Aufgaben des Gastes, älteste zuerst.
     *
     * @return list<ChallengeDraw>
     */
    public function of(Guest $guest): array
    {
        /** @var list<ChallengeDraw> */
        return $this
            ->createQueryBuilder('d')
            ->where('d.guest = :guest')
            ->orderBy('d.id', SortDirection::Ascending)
            ->setParameter('guest', $guest->getId(), UuidType::NAME)
            ->getQuery()
            ->getResult();
    }
}
