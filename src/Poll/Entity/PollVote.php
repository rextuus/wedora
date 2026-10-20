<?php

declare(strict_types=1);

namespace App\Poll\Entity;

use App\Guest\Entity\Guest;
use App\Poll\PollChoice;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Tipp eines Gastes zu einer Frage; pro Gast und Frage genau einer. */
#[ORM\Entity]
#[ORM\Table(name: 'poll_vote')]
#[ORM\UniqueConstraint(name: 'uniq_poll_vote_guest', columns: ['question_id', 'guest_id'])]
class PollVote
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column]
    private DateTimeImmutable $votedAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private PollQuestion $question,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Guest $guest,
        #[ORM\Column(length: 8, enumType: PollChoice::class)]
        private PollChoice $choice,
    ) {
        $this->id = Uuid::v7();
        $this->votedAt = new DateTimeImmutable();
    }

    public function getQuestion(): PollQuestion
    {
        return $this->question;
    }

    public function getGuest(): Guest
    {
        return $this->guest;
    }

    public function getChoice(): PollChoice
    {
        return $this->choice;
    }

    public function change(PollChoice $choice): void
    {
        $this->choice = $choice;
        $this->votedAt = new DateTimeImmutable();
    }
}
