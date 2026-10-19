<?php

declare(strict_types=1);

namespace App\Media\Entity;

use App\Account\Entity\User;
use App\Media\ExportStatus;
use App\Media\Repository\ExportRepository;
use App\Wedding\Entity\Wedding;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * ZIP-Export einer Hochzeit (Originale + Übersicht). Läuft im Hintergrund und
 * wird nach Ablauf automatisch entfernt.
 */
#[ORM\Entity(repositoryClass: ExportRepository::class)]
#[ORM\Table(name: 'media_export')]
class Export
{
    public const string TTL = '+7 days';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 16, enumType: ExportStatus::class)]
    private ExportStatus $status = ExportStatus::Pending;

    #[ORM\Column(type: 'bigint')]
    private int $bytes = 0;

    #[ORM\Column]
    private int $itemCount = 0;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $finishedAt = null;

    #[ORM\Column]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $error = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Wedding $wedding,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $requestedBy,
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

    public function getWedding(): Wedding
    {
        return $this->wedding;
    }

    public function getRequestedBy(): ?User
    {
        return $this->requestedBy;
    }

    public function getStatus(): ExportStatus
    {
        return $this->status;
    }

    public function markReady(int $bytes, int $itemCount, DateTimeImmutable $at): void
    {
        $this->status = ExportStatus::Ready;
        $this->bytes = $bytes;
        $this->itemCount = $itemCount;
        $this->finishedAt = $at;
    }

    public function markFailed(string $error, DateTimeImmutable $at): void
    {
        $this->status = ExportStatus::Failed;
        $this->error = mb_substr($error, 0, 255);
        $this->finishedAt = $at;
    }

    public function getBytes(): int
    {
        return $this->bytes;
    }

    public function getItemCount(): int
    {
        return $this->itemCount;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }
}
