<?php

declare(strict_types=1);

namespace App\Media\Upload;

use App\Guest\Entity\Guest;
use App\Media\Entity\Media;
use App\Media\Entity\Upload;
use App\Wedding\Entity\Wedding;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Speicherverbrauch inkl. Originalen, Varianten und offener (reservierter) Uploads.
 */
final readonly class StorageUsage
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function forWedding(Wedding $wedding, DateTimeImmutable $now): int
    {
        return (
            $this->mediaBytes('m.wedding = :owner', $wedding->getId())
            + $this->reservedBytes('g.wedding = :owner', $wedding->getId(), $now)
        );
    }

    public function forGuest(Guest $guest, DateTimeImmutable $now): int
    {
        return (
            $this->mediaBytes('m.uploaderGuest = :owner', $guest->getId())
            + $this->reservedBytes('u.guest = :owner', $guest->getId(), $now)
        );
    }

    private function mediaBytes(string $condition, Uuid $owner): int
    {
        return (int) $this->em
            ->createQueryBuilder()
            ->select('COALESCE(SUM(m.originalBytes + m.derivedBytes), 0)')
            ->from(Media::class, 'm')
            ->where($condition)
            ->andWhere('m.deletedAt IS NULL')
            // UUID-Schlüssel explizit binär binden (als Entity übergeben würde Doctrine Text binden).
            ->setParameter('owner', $owner, UuidType::NAME)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function reservedBytes(string $condition, Uuid $owner, DateTimeImmutable $now): int
    {
        return (int) $this->em
            ->createQueryBuilder()
            ->select('COALESCE(SUM(u.declaredBytes), 0)')
            ->from(Upload::class, 'u')
            ->join('u.guest', 'g')
            ->where($condition)
            ->andWhere('u.media IS NULL')
            ->andWhere('u.expiresAt > :now')
            ->setParameter('owner', $owner, UuidType::NAME)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
