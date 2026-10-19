<?php

declare(strict_types=1);

namespace App\Social\Entity;

use App\Guest\Entity\Guest;
use App\Media\Entity\Media;
use App\Social\Repository\MediaLikeRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MediaLikeRepository::class)]
#[ORM\Table(name: 'media_like')]
#[ORM\UniqueConstraint(name: 'uniq_like_guest', columns: ['media_id', 'guest_id'])]
class MediaLike
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Media $media,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Guest $guest,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getMedia(): Media
    {
        return $this->media;
    }

    public function getGuest(): Guest
    {
        return $this->guest;
    }
}
