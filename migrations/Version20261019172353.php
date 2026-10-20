<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019172353 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gezeichnete Porträts: wählbare Stile';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE guest ADD portrait_style VARCHAR(16) DEFAULT NULL');
        // Bestehende Hochzeiten: nur der bisherige Stil „feine Linien“.
        $this->addSql('ALTER TABLE wedding ADD portrait_styles JSON DEFAULT NULL');
        $this->addSql("UPDATE wedding SET portrait_styles = '[\"line\"]'");
        $this->addSql('ALTER TABLE wedding MODIFY portrait_styles JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE guest DROP portrait_style');
        $this->addSql('ALTER TABLE wedding DROP portrait_styles');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
