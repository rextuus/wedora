<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schalter „Fotos automatisch angleichen“ (ffmpeg im Worker); Standard aus.
 */
final class Version20261020193956 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fotos automatisch angleichen';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wedding ADD auto_enhance TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wedding DROP auto_enhance');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
