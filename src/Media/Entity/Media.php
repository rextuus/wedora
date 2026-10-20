<?php

declare(strict_types=1);

namespace App\Media\Entity;

use App\Account\Entity\User;
use App\Challenge\Entity\Challenge;
use App\Guest\Entity\Guest;
use App\Media\Contribution;
use App\Media\MediaId;
use App\Media\MediaType;
use App\Media\ProcessingStatus;
use App\Media\PublicationStatus;
use App\Media\Repository\MediaRepository;
use App\Media\Visibility;
use App\Wedding\Entity\Wedding;
use App\Wedding\ModerationMode;
use App\Wedding\ModuleType;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Ein Foto oder Video. Sichtbarkeit (wer darf) und Veröffentlichung (ist freigegeben)
 * sind getrennte Achsen; die Entscheidung trifft MediaAccessPolicy.
 */
#[ORM\Entity(repositoryClass: MediaRepository::class)]
#[ORM\Table(name: 'media')]
#[ORM\Index(name: 'idx_media_wedding_status', columns: ['wedding_id', 'publication_status', 'created_at'])]
class Media implements Contribution
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 16, enumType: PublicationStatus::class)]
    private PublicationStatus $publicationStatus;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    /** Nachträgliche Moderation: null = noch nicht gesichtet. */
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $reviewedAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    #[ORM\Column(length: 16, enumType: ProcessingStatus::class)]
    private ProcessingStatus $processingStatus = ProcessingStatus::Processing;

    /** Für Gäste verständlicher Grund, warum die Verarbeitung fehlschlug. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $processingError = null;

    #[ORM\Column(length: 64)]
    private string $originalMimeType = 'application/octet-stream';

    #[ORM\Column(type: 'bigint')]
    private int $originalBytes = 0;

    /** Summe aller erzeugten Varianten (Video, Standbild) – zählt zum Speicherverbrauch. */
    #[ORM\Column(type: 'bigint')]
    private int $derivedBytes = 0;

    #[ORM\Column(nullable: true)]
    private ?int $width = null;

    #[ORM\Column(nullable: true)]
    private ?int $height = null;

    #[ORM\Column(nullable: true)]
    private ?float $durationSeconds = null;

    /** Kurze Bildunterschrift des Uploaders. */
    #[ORM\Column(length: 140, nullable: true)]
    private ?string $caption = null;

    /** Beitrag zu einer Aufgabe (Modul Challenges). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Challenge $challenge = null;

    private function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Wedding $wedding,
        #[ORM\Column(length: 32, enumType: ModuleType::class)]
        private ModuleType $module,
        #[ORM\Column(length: 16, enumType: MediaType::class)]
        private MediaType $type,
        #[ORM\Column(length: 16, enumType: Visibility::class)]
        private Visibility $visibility,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?Guest $uploaderGuest,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $uploaderUser,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new DateTimeImmutable();
        // „Nur für mich“ sieht niemand sonst und ist der Moderation entzogen – also keine Freigabe nötig.
        $this->publicationStatus =
            ModerationMode::Approval === $wedding->getModerationMode() && Visibility::UploaderOnly !== $visibility
                ? PublicationStatus::Pending
                : PublicationStatus::Published;
    }

    public static function uploadedByGuest(
        Guest $guest,
        ModuleType $module,
        MediaType $type,
        Visibility $visibility,
    ): self {
        return new self($guest->getWedding(), $module, $type, $visibility, $guest, null);
    }

    public static function uploadedByUser(
        User $user,
        Wedding $wedding,
        ModuleType $module,
        MediaType $type,
        Visibility $visibility,
    ): self {
        if (Visibility::UploaderOnly === $visibility) {
            throw new InvalidArgumentException('„Nur für mich“ ist Gästen vorbehalten.');
        }

        return new self($wedding, $module, $type, $visibility, null, $user);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function mediaId(): MediaId
    {
        return new MediaId($this->id->toRfc4122());
    }

    public function getWedding(): Wedding
    {
        return $this->wedding;
    }

    public function getModule(): ModuleType
    {
        return $this->module;
    }

    public function getType(): MediaType
    {
        return $this->type;
    }

    public function getVisibility(): Visibility
    {
        return $this->visibility;
    }

    public function changeVisibility(Visibility $visibility): void
    {
        if (Visibility::UploaderOnly === $visibility && null === $this->uploaderGuest) {
            throw new InvalidArgumentException('„Nur für mich“ ist Gästen vorbehalten.');
        }

        $this->visibility = $visibility;
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

    public function getUploaderGuest(): ?Guest
    {
        return $this->uploaderGuest;
    }

    public function getUploaderUser(): ?User
    {
        return $this->uploaderUser;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function markDeleted(DateTimeImmutable $at): void
    {
        $this->deletedAt ??= $at;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCaption(): ?string
    {
        return $this->caption;
    }

    public function changeCaption(?string $caption): void
    {
        $caption = null === $caption ? '' : trim((string) preg_replace('/\s+/u', ' ', $caption));
        $this->caption = '' === $caption ? null : mb_substr($caption, 0, 140);
    }

    public function getChallenge(): ?Challenge
    {
        return $this->challenge;
    }

    public function assignChallenge(?Challenge $challenge): void
    {
        if (null !== $challenge && $challenge->getWedding() !== $this->wedding) {
            throw new InvalidArgumentException('Aufgabe gehört zu einer anderen Hochzeit.');
        }

        $this->challenge = $challenge;
    }

    public function recordOriginal(
        string $mimeType,
        int $bytes,
        ?int $width = null,
        ?int $height = null,
        ?float $durationSeconds = null,
    ): void {
        $this->originalMimeType = $mimeType;
        $this->originalBytes = $bytes;
        $this->width = $width;
        $this->height = $height;
        $this->durationSeconds = $durationSeconds;
    }

    /** Größe der abgeleiteten Dateien neu setzen (z. B. angeglichene Foto-Fassung erstellt oder entfernt). */
    public function derivedChanged(int $bytes): void
    {
        $this->derivedBytes = max(0, $bytes);
    }

    public function markReady(
        int $derivedBytes = 0,
        ?int $width = null,
        ?int $height = null,
        ?float $durationSeconds = null,
    ): void {
        $this->processingStatus = ProcessingStatus::Ready;
        $this->processingError = null;
        $this->derivedBytes = $derivedBytes;
        $this->width = $width ?? $this->width;
        $this->height = $height ?? $this->height;
        $this->durationSeconds = $durationSeconds ?? $this->durationSeconds;
    }

    public function markFailed(string $reason): void
    {
        $this->processingStatus = ProcessingStatus::Failed;
        $this->processingError = mb_substr($reason, 0, 255);
    }

    public function getProcessingStatus(): ProcessingStatus
    {
        return $this->processingStatus;
    }

    public function isReady(): bool
    {
        return ProcessingStatus::Ready === $this->processingStatus;
    }

    public function getProcessingError(): ?string
    {
        return $this->processingError;
    }

    public function getOriginalMimeType(): string
    {
        return $this->originalMimeType;
    }

    public function getOriginalBytes(): int
    {
        return $this->originalBytes;
    }

    public function getDerivedBytes(): int
    {
        return $this->derivedBytes;
    }

    public function totalBytes(): int
    {
        return $this->originalBytes + $this->derivedBytes;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function getDurationSeconds(): ?float
    {
        return $this->durationSeconds;
    }
}
