<?php

declare(strict_types=1);

namespace App\Challenge\Entity;

use App\Challenge\ChallengeMediaType;
use App\Challenge\Repository\ChallengeRepository;
use App\Wedding\Entity\Wedding;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Eine Foto-/Video-Aufgabe („Mission“) mit optionalem Zeitfenster und Punkten.
 */
#[ORM\Entity(repositoryClass: ChallengeRepository::class)]
#[ORM\Table(name: 'challenge')]
class Challenge
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 80)]
    private string $title;

    #[ORM\Column(length: 500)]
    private string $description = '';

    #[ORM\Column(length: 8, enumType: ChallengeMediaType::class)]
    private ChallengeMediaType $mediaType = ChallengeMediaType::Photo;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private int $points = 0;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $startsAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $endsAt = null;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Wedding $wedding,
        string $title,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new DateTimeImmutable();
        $this->rename($title);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getWedding(): Wedding
    {
        return $this->wedding;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function rename(string $title): void
    {
        $title = trim($title);
        if ('' === $title || mb_strlen($title) > 80) {
            throw new InvalidArgumentException('Titel: 1–80 Zeichen.');
        }

        $this->title = $title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function describe(string $description): void
    {
        $this->description = mb_substr(trim($description), 0, 500);
    }

    public function getMediaType(): ChallengeMediaType
    {
        return $this->mediaType;
    }

    public function setMediaType(ChallengeMediaType $type): void
    {
        $this->mediaType = $type;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }

    public function getPoints(): int
    {
        return $this->points;
    }

    public function setPoints(int $points): void
    {
        $this->points = max(0, min(1000, $points));
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getStartsAt(): ?DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): ?DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function schedule(?DateTimeImmutable $startsAt, ?DateTimeImmutable $endsAt): void
    {
        if (null !== $startsAt && null !== $endsAt && $endsAt <= $startsAt) {
            throw new InvalidArgumentException('Das Ende muss nach dem Beginn liegen.');
        }

        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
    }

    public function hasStartedAt(DateTimeImmutable $now): bool
    {
        return null === $this->startsAt || $now >= $this->startsAt;
    }

    public function hasEndedAt(DateTimeImmutable $now): bool
    {
        return null !== $this->endsAt && $now >= $this->endsAt;
    }

    /** Beiträge möglich: aktiviert, im Zeitfenster und Hochzeit nimmt Beiträge an. */
    public function acceptsAt(DateTimeImmutable $now): bool
    {
        return $this->enabled && $this->hasStartedAt($now) && !$this->hasEndedAt($now) && $this->wedding->isActive();
    }
}
