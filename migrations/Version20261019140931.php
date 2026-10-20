<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019140931 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gezeichnete Gästeporträts (Status am Gast, Schalter pro Hochzeit)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(
            'ALTER TABLE guest ADD portrait_status VARCHAR(16) DEFAULT NULL, ADD portrait_updated_at DATETIME DEFAULT NULL',
        );
        $this->addSql('ALTER TABLE wedding ADD portraits_enabled TINYINT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE guest DROP portrait_status, DROP portrait_updated_at');
        $this->addSql('ALTER TABLE wedding DROP portraits_enabled');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
