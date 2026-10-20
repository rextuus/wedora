<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gemeinsamer Foto-Look der Hochzeit (nur Anzeige per CSS-Filter); Standard: natürlich.
 */
final class Version20261020181014 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Foto-Look der Hochzeit';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wedding ADD photo_look VARCHAR(8) DEFAULT \'natural\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wedding DROP photo_look');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
