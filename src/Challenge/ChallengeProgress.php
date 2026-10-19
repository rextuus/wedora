<?php

declare(strict_types=1);

namespace App\Challenge;

use App\Challenge\Entity\Challenge;
use App\Guest\Entity\Guest;
use App\Media\PublicationStatus;
use App\Wedding\Entity\Wedding;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Wer welche Aufgabe erledigt hat: ein nicht gelöschter, nicht abgelehnter Beitrag zur Aufgabe.
 */
final readonly class ChallengeProgress
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /**
     * @param list<Challenge> $challenges
     *
     * @return array<string, true> erledigte Aufgaben-IDs des Gastes
     */
    public function completedBy(Guest $guest, array $challenges): array
    {
        if ([] === $challenges) {
            return [];
        }

        $ids = $this->em->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT challenge_id FROM media WHERE uploader_guest_id = ? AND challenge_id IN (?)'
            . ' AND deleted_at IS NULL AND publication_status <> ?',
            [$guest->getId()->toBinary(), self::binaryIds($challenges), PublicationStatus::Rejected->value],
            [ParameterType::BINARY, ArrayParameterType::BINARY],
        );

        $done = [];
        foreach (array_filter($ids, is_string(...)) as $id) {
            $done[Uuid::fromBinary($id)->toRfc4122()] = true;
        }

        return $done;
    }

    /**
     * @param list<Challenge> $challenges
     *
     * @return array<string, int> Anzahl Gäste je Aufgabe, die sie erledigt haben
     */
    public function participants(array $challenges): array
    {
        if ([] === $challenges) {
            return [];
        }

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT challenge_id, COUNT(DISTINCT uploader_guest_id) AS n FROM media WHERE challenge_id IN (?)'
            . ' AND deleted_at IS NULL AND publication_status <> ? GROUP BY challenge_id',
            [self::binaryIds($challenges), PublicationStatus::Rejected->value],
            [ArrayParameterType::BINARY],
        );

        $counts = [];
        foreach ($rows as $row) {
            if (is_string($row['challenge_id']) && is_numeric($row['n'])) {
                $counts[Uuid::fromBinary($row['challenge_id'])->toRfc4122()] = (int) $row['n'];
            }
        }

        return $counts;
    }

    /**
     * Rangliste nach Punkten erledigter Aufgaben (jede Aufgabe zählt einmal pro Gast).
     *
     * @return list<array{name: string, points: int, guestId: string}>
     */
    public function leaderboard(Wedding $wedding, int $limit = 10): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT g.id AS guest_id, g.display_name AS name, SUM(c.points) AS points FROM ('
                . ' SELECT DISTINCT m.uploader_guest_id, m.challenge_id FROM media m'
                . ' WHERE m.wedding_id = ? AND m.challenge_id IS NOT NULL AND m.deleted_at IS NULL AND m.publication_status <> ?'
                . ') done JOIN challenge c ON c.id = done.challenge_id JOIN guest g ON g.id = done.uploader_guest_id'
                . ' WHERE c.points > 0 AND g.blocked_at IS NULL GROUP BY g.id, g.display_name ORDER BY points DESC, name ASC LIMIT '
                . max(1, $limit),
            [$wedding->getId()->toBinary(), PublicationStatus::Rejected->value],
        );

        $board = [];
        foreach ($rows as $row) {
            if (is_string($row['guest_id']) && is_string($row['name']) && is_numeric($row['points'])) {
                $board[] = [
                    'name' => $row['name'],
                    'points' => (int) $row['points'],
                    'guestId' => Uuid::fromBinary($row['guest_id'])->toRfc4122(),
                ];
            }
        }

        return $board;
    }

    /**
     * @param list<Challenge> $challenges
     *
     * @return list<string>
     */
    private static function binaryIds(array $challenges): array
    {
        return array_map(static fn(Challenge $c): string => $c->getId()->toBinary(), $challenges);
    }
}
