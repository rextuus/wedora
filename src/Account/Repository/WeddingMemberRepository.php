<?php

declare(strict_types=1);

namespace App\Account\Repository;

use App\Account\Entity\User;
use App\Account\Entity\WeddingMember;
use App\Account\WeddingPermission;
use App\Wedding\Entity\Wedding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WeddingMember>
 */
class WeddingMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WeddingMember::class);
    }

    /**
     * @return array<string, list<WeddingPermission>> Rechte je Hochzeits-ID (RFC 4122)
     */
    public function permissionsByWedding(User $user): array
    {
        $result = [];
        foreach ($this->findBy(['user' => $user]) as $member) {
            $result[$member->getWedding()->getId()->toRfc4122()] = $member->getPermissions();
        }

        return $result;
    }

    public function findOneFor(User $user, Wedding $wedding): ?WeddingMember
    {
        return $this->findOneBy(['user' => $user, 'wedding' => $wedding]);
    }
}
