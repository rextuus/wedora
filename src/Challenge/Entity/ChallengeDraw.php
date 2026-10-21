<?php

declare(strict_types=1);

namespace App\Challenge\Entity;

use App\Challenge\Repository\ChallengeDrawRepository;
use App\Guest\Entity\Guest;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Glücksrad: Diese Aufgabe hat der Gast gezogen. Gezogene Aufgaben bleiben für ihn sichtbar.
 */
#[ORM\Entity(repositoryClass: ChallengeDrawRepository::class)]
#[ORM\Table(name: 'challenge_draw')]
#[ORM\UniqueConstraint(name: 'uniq_challenge_draw', columns: ['guest_id', 'challenge_id'])]
class ChallengeDraw
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Guest $guest,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Challenge $challenge,
        #[ORM\Column]
        private DateTimeImmutable $drawnAt,
    ) {
        $this->id = Uuid::v7();
    }

    public function getChallenge(): Challenge
    {
        return $this->challenge;
    }
}
