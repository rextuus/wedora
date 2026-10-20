<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Codewort je Gast (eindeutig je Hochzeit) zum Wiederherstellen des Zugangs; bestehende Gäste bekommen es beim ersten Anzeigen.
 */
final class Version20261020173052 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Codewort je Gast zum Wiederherstellen des Zugangs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guest ADD codeword VARCHAR(24) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_guest_codeword ON guest (wedding_id, codeword)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_guest_codeword ON guest');
        $this->addSql('ALTER TABLE guest DROP codeword');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
