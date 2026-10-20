<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Aufgabe „Foto mit gezeichnetem Gast“: Zulosungen (buddy_assignment) und Regeln je Aufgabe (Fotos je Partner, Pause).
 */
final class Version20261020170803 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Foto mit gezeichnetem Gast: Zulosungen und Regeln';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE buddy_assignment (id BINARY(16) NOT NULL, ended_at DATETIME DEFAULT NULL, assigned_at DATETIME NOT NULL, media_id BINARY(16) DEFAULT NULL, challenge_id BINARY(16) NOT NULL, seeker_id BINARY(16) NOT NULL, partner_id BINARY(16) NOT NULL, INDEX idx_buddy_partner (partner_id, assigned_at), INDEX IDX_BD508927EA9FDD75 (media_id), INDEX IDX_BD50892798A21AC6 (challenge_id), INDEX IDX_BD50892757555B2 (seeker_id), INDEX IDX_BD5089279393F8FE (partner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'ALTER TABLE buddy_assignment ADD CONSTRAINT FK_BD508927EA9FDD75 FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE SET NULL',
        );
        $this->addSql(
            'ALTER TABLE buddy_assignment ADD CONSTRAINT FK_BD50892798A21AC6 FOREIGN KEY (challenge_id) REFERENCES challenge (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE buddy_assignment ADD CONSTRAINT FK_BD50892757555B2 FOREIGN KEY (seeker_id) REFERENCES guest (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE buddy_assignment ADD CONSTRAINT FK_BD5089279393F8FE FOREIGN KEY (partner_id) REFERENCES guest (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE challenge ADD buddy_limit INT DEFAULT 3 NOT NULL, ADD buddy_pause_minutes INT DEFAULT 30 NOT NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE buddy_assignment DROP FOREIGN KEY FK_BD508927EA9FDD75');
        $this->addSql('ALTER TABLE buddy_assignment DROP FOREIGN KEY FK_BD50892798A21AC6');
        $this->addSql('ALTER TABLE buddy_assignment DROP FOREIGN KEY FK_BD50892757555B2');
        $this->addSql('ALTER TABLE buddy_assignment DROP FOREIGN KEY FK_BD5089279393F8FE');
        $this->addSql('DROP TABLE buddy_assignment');
        $this->addSql('ALTER TABLE challenge DROP buddy_limit, DROP buddy_pause_minutes');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
