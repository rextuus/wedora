<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019172948 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vertrauenshinweis: Betreiber des Servers (Name, Satz, Foto)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(
            'ALTER TABLE wedding ADD host_name VARCHAR(60) DEFAULT NULL, ADD host_note VARCHAR(160) DEFAULT NULL, ADD host_photo_at DATETIME DEFAULT NULL',
        );
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE wedding DROP host_name, DROP host_note, DROP host_photo_at');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
