<?php

declare(strict_types=1);

namespace App\Media\Entity;

use App\Challenge\Entity\Challenge;
use App\Guest\Entity\Guest;
use App\Media\MediaType;
use App\Media\Repository\UploadRepository;
use App\Media\Visibility;
use App\Wedding\Entity\Wedding;
use App\Wedding\ModuleType;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use LogicException;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Fortsetzbarer Upload in Stücken. Die angekündigte Größe ist verbindlich und wird
 * bis zum Abschluss vom Speicherkontingent reserviert.
 */
#[ORM\Entity(repositoryClass: UploadRepository::class)]
#[ORM\Table(name: 'media_upload')]
#[ORM\Index(name: 'idx_upload_expires', columns: ['expires_at'])]
class Upload
{
    public const string TTL = '+24 hours';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: 'bigint')]
    private int $receivedBytes = 0;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $expiresAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Media $media = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Challenge $challenge = null;

    #[ORM\Column(length: 140, nullable: true)]
    private ?string $caption = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Guest $guest,
        #[ORM\Column(length: 32, enumType: ModuleType::class)]
        private ModuleType $module,
        #[ORM\Column(length: 16, enumType: MediaType::class)]
        private MediaType $type,
        #[ORM\Column(length: 16, enumType: Visibility::class)]
        private Visibility $visibility,
        #[ORM\Column(type: 'bigint')]
        private int $declaredBytes,
        DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = $now;
        $this->expiresAt = $now->modify(self::TTL);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGuest(): Guest
    {
        return $this->guest;
    }

    public function getWedding(): Wedding
    {
        return $this->guest->getWedding();
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

    public function getDeclaredBytes(): int
    {
        return $this->declaredBytes;
    }

    public function getReceivedBytes(): int
    {
        return $this->receivedBytes;
    }

    public function received(int $bytes): void
    {
        if ($bytes < 0 || ($this->receivedBytes + $bytes) > $this->declaredBytes) {
            throw new LogicException('Mehr Daten als angekündigt.');
        }

        $this->receivedBytes += $bytes;
    }

    public function isComplete(): bool
    {
        return $this->receivedBytes === $this->declaredBytes;
    }

    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function complete(Media $media): void
    {
        $this->media = $media;
    }

    public function getMedia(): ?Media
    {
        return $this->media;
    }

    public function forChallenge(?Challenge $challenge): void
    {
        $this->challenge = $challenge;
    }

    public function getChallenge(): ?Challenge
    {
        return $this->challenge;
    }

    public function withCaption(?string $caption): void
    {
        $this->caption = $caption;
    }

    public function getCaption(): ?string
    {
        return $this->caption;
    }

    public function isFinished(): bool
    {
        return null !== $this->media;
    }
}
