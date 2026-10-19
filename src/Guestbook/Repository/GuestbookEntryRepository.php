<?php

declare(strict_types=1);

namespace App\Guestbook\Repository;

use App\Guestbook\Entity\GuestbookEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuestbookEntry>
 */
class GuestbookEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuestbookEntry::class);
    }
}
