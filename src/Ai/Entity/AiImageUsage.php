<?php

declare(strict_types=1);

namespace App\Ai\Entity;

use App\Wedding\Entity\Wedding;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Eine angeforderte KI-Bildgenerierung – Grundlage der Kostenbremse (ohne personenbezogene Daten). */
#[ORM\Entity]
#[ORM\Table(name: 'ai_image_usage')]
#[ORM\Index(name: 'idx_ai_usage_created', columns: ['created_at'])]
class AiImageUsage
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    public function __construct(
        #[ORM\Column(length: 32)]
        private string $purpose,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?Wedding $wedding,
        #[ORM\Column]
        private DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
    }
}
