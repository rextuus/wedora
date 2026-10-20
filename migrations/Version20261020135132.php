<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hinweis „Zu viel am Handy?“ optional automatisch zeigen (nach Zeit oder vielen Beiträgen); Standard: nur auf Antippen.
 */
final class Version20261020135132 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Automatischer Hinweis „Zu viel am Handy?“ (Art und Schwelle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE wedding ADD nudge_mode VARCHAR(8) DEFAULT \'tap\' NOT NULL, ADD nudge_threshold INT DEFAULT 0 NOT NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wedding DROP nudge_mode, DROP nudge_threshold');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
