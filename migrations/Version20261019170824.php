<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019170824 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schuhspiel: Fragen, Stimmen der Gäste, Schalter pro Hochzeit';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(
            'CREATE TABLE shoe_game_question (id BINARY(16) NOT NULL, text VARCHAR(140) NOT NULL, position INT NOT NULL, couple_answer VARCHAR(8) DEFAULT NULL, created_at DATETIME NOT NULL, wedding_id BINARY(16) NOT NULL, INDEX IDX_744A00A3FCBBB0ED (wedding_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'CREATE TABLE shoe_game_vote (id BINARY(16) NOT NULL, voted_at DATETIME NOT NULL, choice VARCHAR(8) NOT NULL, question_id BINARY(16) NOT NULL, guest_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_shoe_vote_guest (question_id, guest_id), INDEX IDX_77130F4A1E27F6BF (question_id), INDEX IDX_77130F4A9A4AA658 (guest_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'ALTER TABLE shoe_game_question ADD CONSTRAINT FK_744A00A3FCBBB0ED FOREIGN KEY (wedding_id) REFERENCES wedding (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE shoe_game_vote ADD CONSTRAINT FK_77130F4A1E27F6BF FOREIGN KEY (question_id) REFERENCES shoe_game_question (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE shoe_game_vote ADD CONSTRAINT FK_77130F4A9A4AA658 FOREIGN KEY (guest_id) REFERENCES guest (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE wedding ADD shoe_game_enabled TINYINT NOT NULL, ADD shoe_game_live_results TINYINT NOT NULL',
        );
        // Standard wie in der Entity: Zwischenstand nach eigener Stimme sichtbar.
        $this->addSql('UPDATE wedding SET shoe_game_live_results = 1');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE shoe_game_question DROP FOREIGN KEY FK_744A00A3FCBBB0ED');
        $this->addSql('ALTER TABLE shoe_game_vote DROP FOREIGN KEY FK_77130F4A1E27F6BF');
        $this->addSql('ALTER TABLE shoe_game_vote DROP FOREIGN KEY FK_77130F4A9A4AA658');
        $this->addSql('DROP TABLE shoe_game_question');
        $this->addSql('DROP TABLE shoe_game_vote');
        $this->addSql('ALTER TABLE wedding DROP shoe_game_enabled, DROP shoe_game_live_results');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
