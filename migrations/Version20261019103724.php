<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019103724 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(
            'CREATE TABLE app_user (id BINARY(16) NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, platform_admin TINYINT NOT NULL, created_at DATETIME NOT NULL, display_name VARCHAR(80) NOT NULL, UNIQUE INDEX UNIQ_88BDF3E9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'CREATE TABLE guest (id BINARY(16) NOT NULL, secret_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, blocked_at DATETIME DEFAULT NULL, display_name VARCHAR(40) NOT NULL, join_method VARCHAR(16) NOT NULL, wedding_id BINARY(16) NOT NULL, invitation_id BINARY(16) DEFAULT NULL, INDEX IDX_ACB79A35FCBBB0ED (wedding_id), INDEX IDX_ACB79A35A35D7AF0 (invitation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'CREATE TABLE invitation (id BINARY(16) NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, revoked_at DATETIME DEFAULT NULL, label VARCHAR(120) NOT NULL, expires_at DATETIME DEFAULT NULL, wedding_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_F11D61A2B3BC57DA (token_hash), INDEX IDX_F11D61A2FCBBB0ED (wedding_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'CREATE TABLE media (id BINARY(16) NOT NULL, publication_status VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, reviewed_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, module VARCHAR(32) NOT NULL, type VARCHAR(16) NOT NULL, visibility VARCHAR(16) NOT NULL, wedding_id BINARY(16) NOT NULL, uploader_guest_id BINARY(16) DEFAULT NULL, uploader_user_id BINARY(16) DEFAULT NULL, INDEX idx_media_wedding_status (wedding_id, publication_status, created_at), INDEX IDX_6A2CA10CFCBBB0ED (wedding_id), INDEX IDX_6A2CA10C229C7B66 (uploader_guest_id), INDEX IDX_6A2CA10CCC16E2A2 (uploader_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'CREATE TABLE wedding (id BINARY(16) NOT NULL, slug VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, moderation_mode VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, title VARCHAR(120) NOT NULL, wedding_date DATE DEFAULT NULL, access_open_link_enabled TINYINT NOT NULL, access_open_link_token VARCHAR(64) NOT NULL, access_password_hash VARCHAR(255) DEFAULT NULL, access_invitations_enabled TINYINT NOT NULL, limit_max_video_seconds INT NOT NULL, limit_max_video_bytes BIGINT NOT NULL, limit_max_photo_bytes BIGINT NOT NULL, limit_storage_quota_bytes BIGINT NOT NULL, limit_guest_quota_bytes BIGINT DEFAULT NULL, UNIQUE INDEX UNIQ_5BC25C96989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'CREATE TABLE wedding_member (id BINARY(16) NOT NULL, permissions JSON NOT NULL, label VARCHAR(60) NOT NULL, user_id BINARY(16) NOT NULL, wedding_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_wedding_member (user_id, wedding_id), INDEX IDX_F15AA569A76ED395 (user_id), INDEX IDX_F15AA569FCBBB0ED (wedding_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'CREATE TABLE wedding_module (id BINARY(16) NOT NULL, enabled TINYINT NOT NULL, contributions_close_at DATETIME DEFAULT NULL, type VARCHAR(32) NOT NULL, wedding_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_wedding_module (wedding_id, type), INDEX IDX_8D9A7939FCBBB0ED (wedding_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))',
        );
        $this->addSql(
            'ALTER TABLE guest ADD CONSTRAINT FK_ACB79A35FCBBB0ED FOREIGN KEY (wedding_id) REFERENCES wedding (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE guest ADD CONSTRAINT FK_ACB79A35A35D7AF0 FOREIGN KEY (invitation_id) REFERENCES invitation (id) ON DELETE SET NULL',
        );
        $this->addSql(
            'ALTER TABLE invitation ADD CONSTRAINT FK_F11D61A2FCBBB0ED FOREIGN KEY (wedding_id) REFERENCES wedding (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE media ADD CONSTRAINT FK_6A2CA10CFCBBB0ED FOREIGN KEY (wedding_id) REFERENCES wedding (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE media ADD CONSTRAINT FK_6A2CA10C229C7B66 FOREIGN KEY (uploader_guest_id) REFERENCES guest (id) ON DELETE SET NULL',
        );
        $this->addSql(
            'ALTER TABLE media ADD CONSTRAINT FK_6A2CA10CCC16E2A2 FOREIGN KEY (uploader_user_id) REFERENCES app_user (id) ON DELETE SET NULL',
        );
        $this->addSql(
            'ALTER TABLE wedding_member ADD CONSTRAINT FK_F15AA569A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE wedding_member ADD CONSTRAINT FK_F15AA569FCBBB0ED FOREIGN KEY (wedding_id) REFERENCES wedding (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE wedding_module ADD CONSTRAINT FK_8D9A7939FCBBB0ED FOREIGN KEY (wedding_id) REFERENCES wedding (id) ON DELETE CASCADE',
        );
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE guest DROP FOREIGN KEY FK_ACB79A35FCBBB0ED');
        $this->addSql('ALTER TABLE guest DROP FOREIGN KEY FK_ACB79A35A35D7AF0');
        $this->addSql('ALTER TABLE invitation DROP FOREIGN KEY FK_F11D61A2FCBBB0ED');
        $this->addSql('ALTER TABLE media DROP FOREIGN KEY FK_6A2CA10CFCBBB0ED');
        $this->addSql('ALTER TABLE media DROP FOREIGN KEY FK_6A2CA10C229C7B66');
        $this->addSql('ALTER TABLE media DROP FOREIGN KEY FK_6A2CA10CCC16E2A2');
        $this->addSql('ALTER TABLE wedding_member DROP FOREIGN KEY FK_F15AA569A76ED395');
        $this->addSql('ALTER TABLE wedding_member DROP FOREIGN KEY FK_F15AA569FCBBB0ED');
        $this->addSql('ALTER TABLE wedding_module DROP FOREIGN KEY FK_8D9A7939FCBBB0ED');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE guest');
        $this->addSql('DROP TABLE invitation');
        $this->addSql('DROP TABLE media');
        $this->addSql('DROP TABLE wedding');
        $this->addSql('DROP TABLE wedding_member');
        $this->addSql('DROP TABLE wedding_module');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
