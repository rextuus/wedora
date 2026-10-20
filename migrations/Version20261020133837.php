<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gastgeber-Ansprache (er/sie), Beschreibung und Foto des Servers, wählbares Titelbild der Startseite.
 */
final class Version20261020133837 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ansprache des Gastgebers, Server-Angaben und Titelbild der Startseite';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE wedding ADD host_pronoun VARCHAR(8) DEFAULT NULL, ADD server_note VARCHAR(200) DEFAULT NULL, ADD server_photo_at DATETIME DEFAULT NULL, ADD cover_mode VARCHAR(8) DEFAULT \'latest\' NOT NULL, ADD cover_photo_at DATETIME DEFAULT NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE wedding DROP host_pronoun, DROP server_note, DROP server_photo_at, DROP cover_mode, DROP cover_photo_at',
        );
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
