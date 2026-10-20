<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019122755 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(
            'CREATE TABLE challenge (id BINARY(16) NOT NULL, title VARCHAR(80) NOT NULL, description VARCHAR(500) NOT NULL, media_type VARCHAR(8) NOT NULL, position INT NOT NULL, points INT NOT NULL, enabled TINYINT NOT NULL, starts_at DATETIME DEFAULT NULL, ends_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, wedding_id BINARY(16) NOT NULL, INDEX IDX_D7098951FCBBB0ED (wedding_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'CREATE TABLE guestbook_entry (id BINARY(16) NOT NULL, message LONGTEXT NOT NULL, publication_status VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, reviewed_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, visibility VARCHAR(16) NOT NULL, author_id BINARY(16) NOT NULL, INDEX IDX_A8BA6F71F675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'CREATE TABLE media_like (id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, media_id BINARY(16) NOT NULL, guest_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_like_guest (media_id, guest_id), INDEX IDX_6F249F60EA9FDD75 (media_id), INDEX IDX_6F249F609A4AA658 (guest_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'CREATE TABLE media_report (id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, note VARCHAR(500) NOT NULL, reason VARCHAR(16) NOT NULL, resolved_by_id BINARY(16) DEFAULT NULL, media_id BINARY(16) NOT NULL, reporter_id BINARY(16) DEFAULT NULL, UNIQUE INDEX uniq_report_guest (media_id, reporter_id), INDEX IDX_7856ED5A6713A32B (resolved_by_id), INDEX IDX_7856ED5AEA9FDD75 (media_id), INDEX IDX_7856ED5AE1CFE6F5 (reporter_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'ALTER TABLE challenge ADD CONSTRAINT FK_D7098951FCBBB0ED FOREIGN KEY (wedding_id) REFERENCES wedding (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE guestbook_entry ADD CONSTRAINT FK_A8BA6F71F675F31B FOREIGN KEY (author_id) REFERENCES guest (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE media_like ADD CONSTRAINT FK_6F249F60EA9FDD75 FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE media_like ADD CONSTRAINT FK_6F249F609A4AA658 FOREIGN KEY (guest_id) REFERENCES guest (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE media_report ADD CONSTRAINT FK_7856ED5A6713A32B FOREIGN KEY (resolved_by_id) REFERENCES app_user (id) ON DELETE SET NULL',
        );
        $this->addSql(
            'ALTER TABLE media_report ADD CONSTRAINT FK_7856ED5AEA9FDD75 FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE media_report ADD CONSTRAINT FK_7856ED5AE1CFE6F5 FOREIGN KEY (reporter_id) REFERENCES guest (id) ON DELETE SET NULL',
        );
        $this->addSql(
            'ALTER TABLE media ADD caption VARCHAR(140) DEFAULT NULL, ADD challenge_id BINARY(16) DEFAULT NULL',
        );
        $this->addSql(
            'ALTER TABLE media ADD CONSTRAINT FK_6A2CA10C98A21AC6 FOREIGN KEY (challenge_id) REFERENCES challenge (id) ON DELETE SET NULL',
        );
        $this->addSql('CREATE INDEX IDX_6A2CA10C98A21AC6 ON media (challenge_id)');
        $this->addSql(
            'ALTER TABLE media_upload ADD caption VARCHAR(140) DEFAULT NULL, ADD challenge_id BINARY(16) DEFAULT NULL',
        );
        $this->addSql(
            'ALTER TABLE media_upload ADD CONSTRAINT FK_ABC47CC198A21AC6 FOREIGN KEY (challenge_id) REFERENCES challenge (id) ON DELETE SET NULL',
        );
        $this->addSql('CREATE INDEX IDX_ABC47CC198A21AC6 ON media_upload (challenge_id)');
        $this->addSql('ALTER TABLE wedding ADD challenge_points_enabled TINYINT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE challenge DROP FOREIGN KEY FK_D7098951FCBBB0ED');
        $this->addSql('ALTER TABLE guestbook_entry DROP FOREIGN KEY FK_A8BA6F71F675F31B');
        $this->addSql('ALTER TABLE media_like DROP FOREIGN KEY FK_6F249F60EA9FDD75');
        $this->addSql('ALTER TABLE media_like DROP FOREIGN KEY FK_6F249F609A4AA658');
        $this->addSql('ALTER TABLE media_report DROP FOREIGN KEY FK_7856ED5A6713A32B');
        $this->addSql('ALTER TABLE media_report DROP FOREIGN KEY FK_7856ED5AEA9FDD75');
        $this->addSql('ALTER TABLE media_report DROP FOREIGN KEY FK_7856ED5AE1CFE6F5');
        $this->addSql('DROP TABLE challenge');
        $this->addSql('DROP TABLE guestbook_entry');
        $this->addSql('DROP TABLE media_like');
        $this->addSql('DROP TABLE media_report');
        $this->addSql('ALTER TABLE media DROP FOREIGN KEY FK_6A2CA10C98A21AC6');
        $this->addSql('DROP INDEX IDX_6A2CA10C98A21AC6 ON media');
        $this->addSql('ALTER TABLE media DROP caption, DROP challenge_id');
        $this->addSql('ALTER TABLE media_upload DROP FOREIGN KEY FK_ABC47CC198A21AC6');
        $this->addSql('DROP INDEX IDX_ABC47CC198A21AC6 ON media_upload');
        $this->addSql('ALTER TABLE media_upload DROP caption, DROP challenge_id');
        $this->addSql('ALTER TABLE wedding DROP challenge_points_enabled');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
