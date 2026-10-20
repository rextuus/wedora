<?php

declare(strict_types=1);

namespace App\Challenge\Repository;

use App\Challenge\Entity\BuddyAssignment;
use App\Challenge\Entity\Challenge;
use App\Guest\Entity\Guest;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Abfragen zu den Zulosungen bei „Foto mit gezeichnetem Gast“ (Regeln: PortraitBuddy).
 *
 * @extends ServiceEntityRepository<BuddyAssignment>
 */
class BuddyAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BuddyAssignment::class);
    }

    /** Offene Zulosung des Gastes: nicht beendet und nach $since zugelost. */
    public function open(Guest $seeker, Challenge $challenge, DateTimeImmutable $since): ?BuddyAssignment
    {
        /** @var BuddyAssignment|null */
        return $this
            ->forChallenge($challenge)
            ->andWhere('a.seeker = :seeker')
            ->andWhere('a.endedAt IS NULL')
            ->andWhere('a.assignedAt > :since')
            ->orderBy('a.assignedAt', 'DESC')
            ->setMaxResults(1)
            ->setParameter('seeker', $seeker->getId(), UuidType::NAME)
            ->setParameter('since', $since)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return array<string, int> Fotos je Partner-ID */
    public function photosPerPartner(Challenge $challenge): array
    {
        return self::byPartner(
            $this
                ->forChallenge($challenge)
                ->select('IDENTITY(a.partner) AS partner, COUNT(a.id) AS photos')
                ->andWhere('a.media IS NOT NULL')
                ->groupBy('a.partner')
                ->getQuery()
                ->getArrayResult(),
            'photos',
        );
    }

    /**
     * Partner in der Pause: gerade gesucht (offen seit $searchSince) oder nach $pauseSince fotografiert.
     *
     * @return array<string, int>
     */
    public function busyPartners(
        Challenge $challenge,
        DateTimeImmutable $searchSince,
        DateTimeImmutable $pauseSince,
    ): array {
        return self::byPartner(
            $this
                ->forChallenge($challenge)
                ->select('DISTINCT IDENTITY(a.partner) AS partner')
                ->andWhere(
                    '(a.endedAt IS NULL AND a.assignedAt > :searchSince) OR (a.media IS NOT NULL AND a.endedAt > :pauseSince)',
                )
                ->setParameter('searchSince', $searchSince)
                ->setParameter('pauseSince', $pauseSince)
                ->getQuery()
                ->getArrayResult(),
            null,
        );
    }

    /** @return array<string, int> Partner, mit denen der Gast schon ein Foto hat */
    public function partnersOf(Guest $seeker, Challenge $challenge): array
    {
        return self::byPartner(
            $this
                ->forChallenge($challenge)
                ->select('DISTINCT IDENTITY(a.partner) AS partner')
                ->andWhere('a.seeker = :seeker')
                ->andWhere('a.media IS NOT NULL')
                ->setParameter('seeker', $seeker->getId(), UuidType::NAME)
                ->getQuery()
                ->getArrayResult(),
            null,
        );
    }

    private function forChallenge(Challenge $challenge): QueryBuilder
    {
        return $this
            ->createQueryBuilder('a')
            ->where('a.challenge = :challenge')
            ->setParameter('challenge', $challenge->getId(), UuidType::NAME);
    }

    /**
     * @param array<mixed> $rows
     *
     * @return array<string, int> Partner-ID → Anzahl (ohne Zählspalte: 1)
     */
    private static function byPartner(array $rows, ?string $count): array
    {
        $result = [];
        // @mago-expect analysis:mixed-assignment
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['partner'] ?? null)) {
                continue;
            }

            $id = 16 === strlen($row['partner']) ? Uuid::fromBinary($row['partner'])->toRfc4122() : $row['partner'];
            $result[$id] = match (true) {
                null === $count => 1,
                is_numeric($row[$count] ?? null) => (int) $row[$count],
                default => 0,
            };
        }

        return $result;
    }
}
