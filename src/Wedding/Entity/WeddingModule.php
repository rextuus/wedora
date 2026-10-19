<?php

declare(strict_types=1);

namespace App\Wedding\Entity;

use App\Wedding\ModuleType;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'wedding_module')]
#[ORM\UniqueConstraint(name: 'uniq_wedding_module', columns: ['wedding_id', 'type'])]
class WeddingModule
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column]
    private bool $enabled = true;

    /** Ab hier keine neuen Beiträge mehr (z. B. Ende einer Challenge, Uploadschluss). */
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $contributionsCloseAt = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'modules')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Wedding $wedding,
        #[ORM\Column(length: 32, enumType: ModuleType::class)]
        private ModuleType $type,
    ) {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getWedding(): Wedding
    {
        return $this->wedding;
    }

    public function getType(): ModuleType
    {
        return $this->type;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getContributionsCloseAt(): ?DateTimeImmutable
    {
        return $this->contributionsCloseAt;
    }

    public function closeContributionsAt(?DateTimeImmutable $at): void
    {
        $this->contributionsCloseAt = $at;
    }

    public function acceptsContributionsAt(DateTimeImmutable $now): bool
    {
        return (
            $this->enabled
            && $this->wedding->isActive()
            && (null === $this->contributionsCloseAt || $now < $this->contributionsCloseAt)
        );
    }
}
