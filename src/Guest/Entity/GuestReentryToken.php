<?php

declare(strict_types=1);

namespace App\Guest\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use SensitiveParameter;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Einmal-Code, mit dem die Hochzeit als App vom Home-Bildschirm den Gast wiedererkennt
 * (iPhone-Web-Apps haben einen eigenen Cookie-Speicher). Kurz gültig, nur einmal einlösbar.
 * Gespeichert werden nur der Hash des Codes und das Gast-Geheimnis – verschlüsselt mit dem Code,
 * den nur das Gerät kennt.
 */
#[ORM\Entity]
#[ORM\Table(name: 'guest_reentry_token')]
#[ORM\Index(name: 'idx_reentry_hash', columns: ['token_hash'])]
class GuestReentryToken
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $usedAt = null;

    #[ORM\Column(length: 200)]
    private string $sealedSecret;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Guest $guest,
        #[SensitiveParameter]
        string $token,
        #[SensitiveParameter]
        string $guestSecret,
        #[ORM\Column]
        private DateTimeImmutable $expiresAt,
    ) {
        $this->id = Uuid::v7();
        $this->tokenHash = self::hash($token);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $this->sealedSecret = base64_encode($nonce . sodium_crypto_secretbox($guestSecret, $nonce, self::key($token)));
    }

    /** Gast-Geheimnis mit dem Code entschlüsseln (null bei falschem Code oder beschädigten Daten). */
    public function openSecret(#[SensitiveParameter] string $token): ?string
    {
        $raw = base64_decode($this->sealedSecret, true);
        if (false === $raw || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $secret = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $nonce,
            self::key($token),
        );

        return false === $secret ? null : $secret;
    }

    private static function key(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', 'reentry-key|' . $token, true);
    }

    public static function hash(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', 'reentry|' . $token);
    }

    public function getGuest(): Guest
    {
        return $this->guest;
    }

    public function isRedeemableAt(DateTimeImmutable $now): bool
    {
        return null === $this->usedAt && $now < $this->expiresAt;
    }

    public function redeem(DateTimeImmutable $at): void
    {
        $this->usedAt = $at;
    }
}
