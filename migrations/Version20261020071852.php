<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020071852 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gezeichnete Porträts: zufälliges Accessoire';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE guest ADD portrait_gadget VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE wedding ADD portrait_gadgets TINYINT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE guest DROP portrait_gadget');
        $this->addSql('ALTER TABLE wedding DROP portrait_gadgets');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
