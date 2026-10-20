<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Figur „Zu viel am Handy?“ taucht erst auf, wenn die Bedingung erfüllt ist: „nur auf Antippen“ entfällt,
 * neuer Standard ist „nach 20 Minuten in der App“.
 */
final class Version20261020141500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Handy-Hinweis: Standard „nach 20 Minuten“ statt „nur auf Antippen“';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE wedding SET nudge_mode = 'time', nudge_threshold = 20 WHERE nudge_mode = 'tap'");
        $this->addSql(
            "ALTER TABLE wedding CHANGE nudge_mode nudge_mode VARCHAR(8) DEFAULT 'time' NOT NULL, CHANGE nudge_threshold nudge_threshold INT DEFAULT 20 NOT NULL",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE wedding CHANGE nudge_mode nudge_mode VARCHAR(8) DEFAULT 'tap' NOT NULL, CHANGE nudge_threshold nudge_threshold INT DEFAULT 0 NOT NULL",
        );
        $this->addSql("UPDATE wedding SET nudge_mode = 'tap', nudge_threshold = 0 WHERE nudge_mode = 'off'");
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
