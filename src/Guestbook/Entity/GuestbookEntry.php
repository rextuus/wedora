<?php

declare(strict_types=1);

namespace App\Guestbook\Entity;

use App\Account\Entity\User;
use App\Guest\Entity\Guest;
use App\Guestbook\Repository\GuestbookEntryRepository;
use App\Media\Contribution;
use App\Media\PublicationStatus;
use App\Media\Visibility;
use App\Wedding\Entity\Wedding;
use App\Wedding\ModerationMode;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Text im digitalen Gästebuch. Foto- und Video-Grüße sind Medien im Modul „Gästebuch“.
 */
#[ORM\Entity(repositoryClass: GuestbookEntryRepository::class)]
#[ORM\Table(name: 'guestbook_entry')]
class GuestbookEntry implements Contribution
{
    public const int MAX_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\Column(length: 16, enumType: PublicationStatus::class)]
    private PublicationStatus $publicationStatus;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $reviewedAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Guest $author,
        string $message,
        #[ORM\Column(length: 16, enumType: Visibility::class)]
        private Visibility $visibility = Visibility::AllGuests,
    ) {
        if (Visibility::UploaderOnly === $visibility) {
            throw new InvalidArgumentException('Gästebucheinträge sind für Gäste oder das Brautpaar bestimmt.');
        }

        $message = trim(str_replace("\r\n", "\n", $message));
        if ('' === $message || mb_strlen($message) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('Nachricht: 1–%d Zeichen.', self::MAX_LENGTH));
        }

        $this->id = Uuid::v7();
        $this->message = $message;
        $this->createdAt = new DateTimeImmutable();
        $this->publicationStatus = ModerationMode::Approval === $author->getWedding()->getModerationMode()
            ? PublicationStatus::Pending
            : PublicationStatus::Published;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getWedding(): Wedding
    {
        return $this->author->getWedding();
    }

    public function getAuthor(): Guest
    {
        return $this->author;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getVisibility(): Visibility
    {
        return $this->visibility;
    }

    public function getPublicationStatus(): PublicationStatus
    {
        return $this->publicationStatus;
    }

    public function approve(DateTimeImmutable $at): void
    {
        $this->publicationStatus = PublicationStatus::Published;
        $this->reviewedAt = $at;
    }

    public function reject(DateTimeImmutable $at): void
    {
        $this->publicationStatus = PublicationStatus::Rejected;
        $this->reviewedAt = $at;
    }

    /** Nachträgliche Moderation: gesichtet, bleibt veröffentlicht. */
    public function markReviewed(DateTimeImmutable $at): void
    {
        $this->reviewedAt ??= $at;
    }

    public function getReviewedAt(): ?DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function markDeleted(DateTimeImmutable $at): void
    {
        $this->deletedAt ??= $at;
    }

    public function isReady(): bool
    {
        return true;
    }

    public function getUploaderGuest(): ?Guest
    {
        return $this->author;
    }

    public function getUploaderUser(): ?User
    {
        return null;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
