<?php

declare(strict_types=1);

namespace App\Wedding\Entity;

use Doctrine\ORM\Mapping as ORM;
use SensitiveParameter;

/**
 * Zugangswege einer Hochzeit. Kombinierbar:
 * - offener Link/QR (Token im Link), optional zusätzlich mit Hochzeitspasswort,
 * - persönliche Einladungslinks (umgehen das Passwort, sind einzeln widerrufbar).
 */
#[ORM\Embeddable]
class AccessPolicy
{
    #[ORM\Column]
    private bool $openLinkEnabled = true;

    /** Klartext, weil der QR-Code jederzeit neu gedruckt werden können muss. */
    #[ORM\Column(length: 64)]
    private string $openLinkToken;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $passwordHash = null;

    #[ORM\Column]
    private bool $invitationsEnabled = true;

    public function __construct()
    {
        $this->openLinkToken = self::newToken();
    }

    public function isOpenLinkEnabled(): bool
    {
        return $this->openLinkEnabled;
    }

    public function openLinkToken(): string
    {
        return $this->openLinkToken;
    }

    public function acceptsOpenLinkToken(#[SensitiveParameter] string $token): bool
    {
        return $this->openLinkEnabled && hash_equals($this->openLinkToken, $token);
    }

    public function enableOpenLink(bool $enabled): void
    {
        $this->openLinkEnabled = $enabled;
    }

    /** Neuer QR-Code: der alte Link funktioniert danach nicht mehr für neue Beitritte. */
    public function rotateOpenLinkToken(): void
    {
        $this->openLinkToken = self::newToken();
    }

    public function requiresPassword(): bool
    {
        return null !== $this->passwordHash;
    }

    public function passwordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(?string $hash): void
    {
        $this->passwordHash = $hash;
    }

    public function areInvitationsEnabled(): bool
    {
        return $this->invitationsEnabled;
    }

    public function enableInvitations(bool $enabled): void
    {
        $this->invitationsEnabled = $enabled;
    }

    private static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }
}
