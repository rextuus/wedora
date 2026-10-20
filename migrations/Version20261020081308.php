<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020081308 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Kostenbremse: Zähler für KI-Bilder';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(
            'CREATE TABLE ai_image_usage (id BINARY(16) NOT NULL, purpose VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, wedding_id BINARY(16) DEFAULT NULL, INDEX idx_ai_usage_created (created_at), INDEX IDX_ABC96966FCBBB0ED (wedding_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'ALTER TABLE ai_image_usage ADD CONSTRAINT FK_ABC96966FCBBB0ED FOREIGN KEY (wedding_id) REFERENCES wedding (id) ON DELETE SET NULL',
        );
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ai_image_usage DROP FOREIGN KEY FK_ABC96966FCBBB0ED');
        $this->addSql('DROP TABLE ai_image_usage');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
