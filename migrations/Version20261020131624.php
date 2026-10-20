<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gezeichnete Porträts für andere Gäste freigeben (Gästewand „Wer ist alles da?“); bestehende bleiben privat.
 */
final class Version20261020131624 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Freigabe des eigenen Porträts für andere Gäste (Standard: privat)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guest ADD portrait_shared TINYINT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guest DROP portrait_shared');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
