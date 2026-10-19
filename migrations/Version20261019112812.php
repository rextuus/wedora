<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019112812 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(
            'CREATE TABLE media_export (id BINARY(16) NOT NULL, status VARCHAR(16) NOT NULL, bytes BIGINT NOT NULL, item_count INT NOT NULL, created_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, expires_at DATETIME NOT NULL, error VARCHAR(255) DEFAULT NULL, wedding_id BINARY(16) NOT NULL, requested_by_id BINARY(16) DEFAULT NULL, INDEX IDX_FEF58C4AFCBBB0ED (wedding_id), INDEX IDX_FEF58C4A4DA1E751 (requested_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'CREATE TABLE media_upload (id BINARY(16) NOT NULL, received_bytes BIGINT NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, module VARCHAR(32) NOT NULL, type VARCHAR(16) NOT NULL, visibility VARCHAR(16) NOT NULL, declared_bytes BIGINT NOT NULL, media_id BINARY(16) DEFAULT NULL, guest_id BINARY(16) NOT NULL, INDEX idx_upload_expires (expires_at), INDEX IDX_ABC47CC1EA9FDD75 (media_id), INDEX IDX_ABC47CC19A4AA658 (guest_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'ALTER TABLE media_export ADD CONSTRAINT FK_FEF58C4AFCBBB0ED FOREIGN KEY (wedding_id) REFERENCES wedding (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE media_export ADD CONSTRAINT FK_FEF58C4A4DA1E751 FOREIGN KEY (requested_by_id) REFERENCES app_user (id) ON DELETE SET NULL',
        );
        $this->addSql(
            'ALTER TABLE media_upload ADD CONSTRAINT FK_ABC47CC1EA9FDD75 FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE SET NULL',
        );
        $this->addSql(
            'ALTER TABLE media_upload ADD CONSTRAINT FK_ABC47CC19A4AA658 FOREIGN KEY (guest_id) REFERENCES guest (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE media ADD processing_status VARCHAR(16) NOT NULL, ADD processing_error VARCHAR(255) DEFAULT NULL, ADD original_mime_type VARCHAR(64) NOT NULL, ADD original_bytes BIGINT NOT NULL, ADD derived_bytes BIGINT NOT NULL, ADD width INT DEFAULT NULL, ADD height INT DEFAULT NULL, ADD duration_seconds DOUBLE PRECISION DEFAULT NULL',
        );
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE media_export DROP FOREIGN KEY FK_FEF58C4AFCBBB0ED');
        $this->addSql('ALTER TABLE media_export DROP FOREIGN KEY FK_FEF58C4A4DA1E751');
        $this->addSql('ALTER TABLE media_upload DROP FOREIGN KEY FK_ABC47CC1EA9FDD75');
        $this->addSql('ALTER TABLE media_upload DROP FOREIGN KEY FK_ABC47CC19A4AA658');
        $this->addSql('DROP TABLE media_export');
        $this->addSql('DROP TABLE media_upload');
        $this->addSql(
            'ALTER TABLE media DROP processing_status, DROP processing_error, DROP original_mime_type, DROP original_bytes, DROP derived_bytes, DROP width, DROP height, DROP duration_seconds',
        );
    }
}
