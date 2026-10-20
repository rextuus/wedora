<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020083553 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Kostenbremse: Grenzen aus der Verwaltung';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(
            'CREATE TABLE ai_budget_setting (id INT NOT NULL, daily_limit INT NOT NULL, monthly_limit INT NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE ai_budget_setting');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
