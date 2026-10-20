<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020071112 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Einmal-Links für die Hochzeit als App auf dem Home-Bildschirm';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(
            'CREATE TABLE guest_reentry_token (id BINARY(16) NOT NULL, token_hash VARCHAR(64) NOT NULL, used_at DATETIME DEFAULT NULL, sealed_secret VARCHAR(200) NOT NULL, expires_at DATETIME NOT NULL, guest_id BINARY(16) NOT NULL, INDEX idx_reentry_hash (token_hash), INDEX IDX_8081F0DC9A4AA658 (guest_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'ALTER TABLE guest_reentry_token ADD CONSTRAINT FK_8081F0DC9A4AA658 FOREIGN KEY (guest_id) REFERENCES guest (id) ON DELETE CASCADE',
        );
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE guest_reentry_token DROP FOREIGN KEY FK_8081F0DC9A4AA658');
        $this->addSql('DROP TABLE guest_reentry_token');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
