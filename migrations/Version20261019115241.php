<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019115241 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(
            'CREATE TABLE video_processing_record (id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, input_seconds DOUBLE PRECISION NOT NULL, input_bytes BIGINT NOT NULL, input_width INT NOT NULL, input_height INT NOT NULL, input_codec VARCHAR(32) NOT NULL, processing_ms INT NOT NULL, queue_wait_ms INT NOT NULL, succeeded TINYINT NOT NULL, output_bytes BIGINT DEFAULT NULL, failure_reason VARCHAR(255) DEFAULT NULL, threads INT NOT NULL, INDEX idx_vpr_success_created (succeeded, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE video_processing_record');
    }
}
