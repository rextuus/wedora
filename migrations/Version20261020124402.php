<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Umfrage „Wer von beiden?“ wird eine normale Aufgabe (Art „Umfrage“): Jede Hochzeit mit eingeschalteter
 * Umfrage oder vorhandenen Fragen bekommt die Aufgabe (ein/aus wie vorher), danach entfällt der eigene Schalter.
 * Fragen und Stimmen bleiben unverändert.
 */
final class Version20261020124402 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Umfrage „Wer von beiden?“ als Aufgabe statt eigenem Schalter';
    }

    public function up(Schema $schema): void
    {
        $weddings = $this->connection->fetchAllAssociative(
            'SELECT w.id, w.poll_enabled, COALESCE(MAX(c.position) + 1, 0) AS next_position FROM wedding w'
            . ' LEFT JOIN challenge c ON c.wedding_id = w.id'
            . ' WHERE (w.poll_enabled = 1 OR EXISTS (SELECT 1 FROM poll_question q WHERE q.wedding_id = w.id))'
            . " AND NOT EXISTS (SELECT 1 FROM challenge p WHERE p.wedding_id = w.id AND p.media_type = 'poll')"
            . ' GROUP BY w.id, w.poll_enabled',
        );
        foreach ($weddings as $wedding) {
            $this->addSql(
                'INSERT INTO challenge (id, wedding_id, title, description, media_type, position, points, enabled, starts_at, ends_at, created_at)'
                . " VALUES (?, ?, 'Wer von beiden?', 'Kleine Umfrage: Was meinst du, wer von den beiden macht was mehr?', 'poll', ?, 10, ?, NULL, NULL, NOW())",
                [
                    Uuid::v7()->toBinary(),
                    $wedding['id'],
                    (int) $wedding['next_position'],
                    (int) $wedding['poll_enabled'],
                ],
                [ParameterType::BINARY, ParameterType::BINARY, ParameterType::INTEGER, ParameterType::INTEGER],
            );
        }

        $this->addSql('ALTER TABLE wedding DROP poll_enabled');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wedding ADD poll_enabled TINYINT DEFAULT 0 NOT NULL');
        $this->addSql(
            "UPDATE wedding w SET poll_enabled = 1 WHERE EXISTS (SELECT 1 FROM challenge c WHERE c.wedding_id = w.id AND c.media_type = 'poll' AND c.enabled = 1)",
        );
        $this->addSql("DELETE FROM challenge WHERE media_type = 'poll'");
        $this->addSql('ALTER TABLE wedding ALTER poll_enabled DROP DEFAULT');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
