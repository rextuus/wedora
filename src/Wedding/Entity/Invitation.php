<?php

declare(strict_types=1);

namespace App\Wedding\Entity;

use App\Wedding\Repository\InvitationRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use SensitiveParameter;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Persönlicher Einladungslink. Gespeichert wird nur der Hash des Tokens; der Link
 * ist nur beim Erzeugen bekannt. Widerruf sperrt auch alle Gäste, die über ihn beigetreten sind.
 */
#[ORM\Entity(repositoryClass: InvitationRepository::class)]
#[ORM\Table(name: 'invitation')]
class Invitation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    private function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Wedding $wedding,
        #[ORM\Column(length: 120)]
        private string $label,
        #[SensitiveParameter]
        string $token,
        /** Bis wann der Link zum Beitreten genutzt werden kann. */
        #[ORM\Column(nullable: true)]
        private ?DateTimeImmutable $expiresAt,
    ) {
        $this->id = Uuid::v7();
        $this->tokenHash = self::hashToken($token);
        $this->createdAt = new DateTimeImmutable();
    }

    /**
     * @return array{Invitation, string} Einladung und Klartext-Token (nur jetzt verfügbar)
     */
    public static function create(Wedding $wedding, string $label, ?DateTimeImmutable $expiresAt = null): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

        return [new self($wedding, $label, $token, $expiresAt), $token];
    }

    public static function hashToken(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', 'invitation|' . $token);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getWedding(): Wedding
    {
        return $this->wedding;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function revoke(DateTimeImmutable $at): void
    {
        $this->revokedAt ??= $at;
    }

    public function canBeUsedToJoinAt(DateTimeImmutable $now): bool
    {
        return (
            !$this->isRevoked()
            && (null === $this->expiresAt || $now < $this->expiresAt)
            && $this->wedding->isActive()
            && $this->wedding->access()->areInvitationsEnabled()
        );
    }
}
