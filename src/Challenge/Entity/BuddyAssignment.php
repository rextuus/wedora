<?php

declare(strict_types=1);

namespace App\Challenge\Entity;

use App\Challenge\Repository\BuddyAssignmentRepository;
use App\Guest\Entity\Guest;
use App\Media\Entity\Media;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Zulosung bei „Foto mit gezeichnetem Gast“: wer (Sucher) wen (Partner) suchen soll.
 * Offen, solange nicht beendet und nicht abgelaufen; erledigt mit dem gemeinsamen Foto.
 */
#[ORM\Entity(repositoryClass: BuddyAssignmentRepository::class)]
#[ORM\Table(name: 'buddy_assignment')]
#[ORM\Index(name: 'idx_buddy_partner', columns: ['partner_id', 'assigned_at'])]
class BuddyAssignment
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** Abgebrochen („Jemand anderen“) oder mit Foto erledigt. */
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $endedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Media $media = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Challenge $challenge,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Guest $seeker,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Guest $partner,
        #[ORM\Column]
        private DateTimeImmutable $assignedAt,
    ) {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getChallenge(): Challenge
    {
        return $this->challenge;
    }

    public function getSeeker(): Guest
    {
        return $this->seeker;
    }

    public function getPartner(): Guest
    {
        return $this->partner;
    }

    public function getAssignedAt(): DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function getMedia(): ?Media
    {
        return $this->media;
    }

    public function isEnded(): bool
    {
        return null !== $this->endedAt;
    }

    public function completeWith(Media $media, DateTimeImmutable $at): void
    {
        $this->media = $media;
        $this->endedAt = $at;
    }

    public function skip(DateTimeImmutable $at): void
    {
        $this->endedAt ??= $at;
    }
}
