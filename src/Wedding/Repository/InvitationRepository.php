<?php

declare(strict_types=1);

namespace App\Wedding\Repository;

use App\Wedding\Entity\Invitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SensitiveParameter;

/**
 * @extends ServiceEntityRepository<Invitation>
 */
class InvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invitation::class);
    }

    public function findOneByToken(#[SensitiveParameter] string $token): ?Invitation
    {
        return $this->findOneBy(['tokenHash' => Invitation::hashToken($token)]);
    }
}
