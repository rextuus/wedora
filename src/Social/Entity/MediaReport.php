<?php

declare(strict_types=1);

namespace App\Social\Entity;

use App\Account\Entity\User;
use App\Guest\Entity\Guest;
use App\Media\Entity\Media;
use App\Social\ReportReason;
use App\Social\Repository\MediaReportRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Meldung eines Beitrags durch einen Gast; erscheint in der Moderation.
 */
#[ORM\Entity(repositoryClass: MediaReportRepository::class)]
#[ORM\Table(name: 'media_report')]
#[ORM\UniqueConstraint(name: 'uniq_report_guest', columns: ['media_id', 'reporter_id'])]
class MediaReport
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $resolvedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $resolvedBy = null;

    #[ORM\Column(length: 500)]
    private string $note;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Media $media,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?Guest $reporter,
        #[ORM\Column(length: 16, enumType: ReportReason::class)]
        private ReportReason $reason,
        string $note = '',
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new DateTimeImmutable();
        $this->note = mb_substr(trim($note), 0, 500);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMedia(): Media
    {
        return $this->media;
    }

    public function getReporter(): ?Guest
    {
        return $this->reporter;
    }

    public function getReason(): ReportReason
    {
        return $this->reason;
    }

    public function getNote(): string
    {
        return $this->note;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isResolved(): bool
    {
        return null !== $this->resolvedAt;
    }

    public function resolve(User $by, DateTimeImmutable $at): void
    {
        $this->resolvedAt ??= $at;
        $this->resolvedBy ??= $by;
    }
}
