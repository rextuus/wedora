<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stil der Gastseiten je Hochzeit (botanisch oder handgeschrieben).
 */
final class Version20261025194700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stil der Seite';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wedding ADD theme VARCHAR(12) DEFAULT \'botanical\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wedding DROP theme');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
