<?php

declare(strict_types=1);

namespace App\Poll\Entity;

use App\Poll\Repository\PollQuestionRepository;
use App\Wedding\Entity\Wedding;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Eine Frage der Umfrage „Wer von beiden …?“ (z. B. „Wer tanzt heute mehr?“).
 */
#[ORM\Entity(repositoryClass: PollQuestionRepository::class)]
#[ORM\Table(name: 'poll_question')]
class PollQuestion
{
    public const int MAX_LENGTH = 140;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: self::MAX_LENGTH)]
    private string $text;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Wedding $wedding,
        string $text,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new DateTimeImmutable();
        $this->rephrase($text);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getWedding(): Wedding
    {
        return $this->wedding;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function rephrase(string $text): void
    {
        $text = trim($text);
        if ('' === $text || mb_strlen($text) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('Frage: 1–%d Zeichen.', self::MAX_LENGTH));
        }

        $this->text = $text;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }
}
