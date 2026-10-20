<?php

declare(strict_types=1);

namespace App\Poll\Repository;

use App\Poll\Entity\PollQuestion;
use App\Wedding\Entity\Wedding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PollQuestion>
 */
class PollQuestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PollQuestion::class);
    }

    /**
     * @return list<PollQuestion>
     */
    public function forWedding(Wedding $wedding): array
    {
        return $this->findBy(['wedding' => $wedding], ['position' => 'ASC', 'createdAt' => 'ASC']);
    }
}
