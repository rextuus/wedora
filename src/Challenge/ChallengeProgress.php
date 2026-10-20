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
 * Wer welche Aufgabe erledigt hat: ein nicht gelöschter, nicht abgelehnter Beitrag zur Aufgabe –
 * bei der Umfrage „Wer von beiden?“ eine Antwort auf jede Frage der Hochzeit.
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
            'SELECT challenge_id FROM '
            . self::done('m.challenge_id IN (:challenges)', 'c.id IN (:challenges)')
            . ' WHERE guest_id = :guest',
            [
                'challenges' => self::binaryIds($challenges),
                'guest' => $guest->getId()->toBinary(),
                'rejected' => PublicationStatus::Rejected->value,
            ],
            ['challenges' => ArrayParameterType::BINARY, 'guest' => ParameterType::BINARY],
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
            'SELECT challenge_id, COUNT(*) AS n FROM '
            . self::done('m.challenge_id IN (:challenges)', 'c.id IN (:challenges)')
            . ' GROUP BY challenge_id',
            ['challenges' => self::binaryIds($challenges), 'rejected' => PublicationStatus::Rejected->value],
            ['challenges' => ArrayParameterType::BINARY],
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
            'SELECT g.id AS guest_id, g.display_name AS name, SUM(c.points) AS points FROM '
                . self::done('m.wedding_id = :wedding', 'c.wedding_id = :wedding')
                . ' JOIN challenge c ON c.id = done.challenge_id JOIN guest g ON g.id = done.guest_id'
                . ' WHERE c.points > 0 AND g.blocked_at IS NULL GROUP BY g.id, g.display_name ORDER BY points DESC, name ASC LIMIT '
                . max(1, $limit),
            ['wedding' => $wedding->getId()->toBinary(), 'rejected' => PublicationStatus::Rejected->value],
            ['wedding' => ParameterType::BINARY],
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
     * Abgeleitete Tabelle „done“ (guest_id, challenge_id), je Gast und Aufgabe höchstens eine Zeile:
     * Beiträge zu Foto-/Video-Aufgaben und vollständig beantwortete Umfragen. Erwartet den Parameter :rejected.
     */
    private static function done(string $mediaFilter, string $pollFilter): string
    {
        return (
            '(SELECT m.uploader_guest_id AS guest_id, m.challenge_id FROM media m'
            . ' WHERE '
            . $mediaFilter
            . ' AND m.uploader_guest_id IS NOT NULL AND m.challenge_id IS NOT NULL'
            . ' AND m.deleted_at IS NULL AND m.publication_status <> :rejected'
            . ' UNION SELECT v.guest_id, c.id FROM challenge c'
            . ' JOIN (SELECT wedding_id, COUNT(*) AS total FROM poll_question GROUP BY wedding_id) t ON t.wedding_id = c.wedding_id'
            . ' JOIN poll_question q ON q.wedding_id = c.wedding_id JOIN poll_vote v ON v.question_id = q.id'
            . " WHERE c.media_type = 'poll' AND "
            . $pollFilter
            . ' GROUP BY v.guest_id, c.id, t.total HAVING COUNT(*) = t.total) done'
        );
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
