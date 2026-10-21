<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Glücksrad für Aufgaben: Schalter je Hochzeit und gezogene Aufgaben je Gast.
 */
final class Version20261020225149 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Aufgaben per Glücksrad';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE challenge_draw (id BINARY(16) NOT NULL, drawn_at DATETIME NOT NULL, guest_id BINARY(16) NOT NULL, challenge_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_challenge_draw (guest_id, challenge_id), INDEX IDX_795DBA899A4AA658 (guest_id), INDEX IDX_795DBA8998A21AC6 (challenge_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'ALTER TABLE challenge_draw ADD CONSTRAINT FK_795DBA899A4AA658 FOREIGN KEY (guest_id) REFERENCES guest (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE challenge_draw ADD CONSTRAINT FK_795DBA8998A21AC6 FOREIGN KEY (challenge_id) REFERENCES challenge (id) ON DELETE CASCADE',
        );
        $this->addSql('ALTER TABLE wedding ADD challenge_wheel TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE challenge_draw DROP FOREIGN KEY FK_795DBA899A4AA658');
        $this->addSql('ALTER TABLE challenge_draw DROP FOREIGN KEY FK_795DBA8998A21AC6');
        $this->addSql('DROP TABLE challenge_draw');
        $this->addSql('ALTER TABLE wedding DROP challenge_wheel');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
