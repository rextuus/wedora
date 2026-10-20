<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schuhspiel → Umfrage „Wer von beiden?“: Tabellen und Spalten umbenennen (Fragen und Stimmen bleiben erhalten),
 * die Auflösung durch das Paar entfällt.
 */
final class Version20261020090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schuhspiel wird Umfrage „Wer von beiden?“ (Umbenennung, Auflösung entfällt)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE shoe_game_vote DROP FOREIGN KEY FK_77130F4A1E27F6BF, DROP FOREIGN KEY FK_77130F4A9A4AA658',
        );
        $this->addSql('ALTER TABLE shoe_game_question DROP FOREIGN KEY FK_744A00A3FCBBB0ED');
        $this->addSql('RENAME TABLE shoe_game_question TO poll_question, shoe_game_vote TO poll_vote');
        $this->addSql(
            'ALTER TABLE poll_question DROP couple_answer, RENAME INDEX IDX_744A00A3FCBBB0ED TO IDX_6D31FEFCBBB0ED',
        );
        $this->addSql(
            'ALTER TABLE poll_vote RENAME INDEX uniq_shoe_vote_guest TO uniq_poll_vote_guest, RENAME INDEX IDX_77130F4A1E27F6BF TO IDX_ED568EBE1E27F6BF, RENAME INDEX IDX_77130F4A9A4AA658 TO IDX_ED568EBE9A4AA658',
        );
        $this->addSql(
            'ALTER TABLE poll_question ADD CONSTRAINT FK_6D31FEFCBBB0ED FOREIGN KEY (wedding_id) REFERENCES wedding (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE poll_vote ADD CONSTRAINT FK_ED568EBE1E27F6BF FOREIGN KEY (question_id) REFERENCES poll_question (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE poll_vote ADD CONSTRAINT FK_ED568EBE9A4AA658 FOREIGN KEY (guest_id) REFERENCES guest (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE wedding RENAME COLUMN shoe_game_enabled TO poll_enabled, RENAME COLUMN shoe_game_live_results TO poll_live_results',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE wedding RENAME COLUMN poll_enabled TO shoe_game_enabled, RENAME COLUMN poll_live_results TO shoe_game_live_results',
        );
        $this->addSql(
            'ALTER TABLE poll_vote DROP FOREIGN KEY FK_ED568EBE1E27F6BF, DROP FOREIGN KEY FK_ED568EBE9A4AA658',
        );
        $this->addSql('ALTER TABLE poll_question DROP FOREIGN KEY FK_6D31FEFCBBB0ED');
        $this->addSql(
            'ALTER TABLE poll_vote RENAME INDEX uniq_poll_vote_guest TO uniq_shoe_vote_guest, RENAME INDEX IDX_ED568EBE1E27F6BF TO IDX_77130F4A1E27F6BF, RENAME INDEX IDX_ED568EBE9A4AA658 TO IDX_77130F4A9A4AA658',
        );
        $this->addSql(
            'ALTER TABLE poll_question ADD couple_answer VARCHAR(8) DEFAULT NULL, RENAME INDEX IDX_6D31FEFCBBB0ED TO IDX_744A00A3FCBBB0ED',
        );
        $this->addSql('RENAME TABLE poll_question TO shoe_game_question, poll_vote TO shoe_game_vote');
        $this->addSql(
            'ALTER TABLE shoe_game_question ADD CONSTRAINT FK_744A00A3FCBBB0ED FOREIGN KEY (wedding_id) REFERENCES wedding (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE shoe_game_vote ADD CONSTRAINT FK_77130F4A1E27F6BF FOREIGN KEY (question_id) REFERENCES shoe_game_question (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE shoe_game_vote ADD CONSTRAINT FK_77130F4A9A4AA658 FOREIGN KEY (guest_id) REFERENCES guest (id) ON DELETE CASCADE',
        );
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
