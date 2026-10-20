<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019173058 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gezeichnete Porträts: Stil per Zufall oder Wahl des Gastes';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE wedding ADD portrait_style_mode VARCHAR(8) DEFAULT \'random\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE wedding DROP portrait_style_mode');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
