<?php

declare(strict_types=1);

namespace App\Guest;

use App\Guest\Entity\Guest;
use App\Guest\Entity\GuestReentryToken;
use App\Wedding\Entity\Wedding;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

/**
 * Einmal-Codes für den Wiedereinstieg über die App auf dem Home-Bildschirm.
 */
final readonly class AppReentry
{
    public const int VALID_MINUTES = 15;

    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {}

    /**
     * Neuer Code; nur jetzt im Klartext verfügbar.
     *
     * @param string $guestSecret Geheimnis aus dem aktuellen Gast-Cookie (wird mit dem Code verschlüsselt abgelegt)
     */
    public function issue(Guest $guest, #[SensitiveParameter] string $guestSecret): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->em->persist(
            new GuestReentryToken(
                $guest,
                $token,
                $guestSecret,
                $this->now()->modify(sprintf('+%d minutes', self::VALID_MINUTES)),
            ),
        );
        $this->em->flush();

        return $token;
    }

    /**
     * Löst den Code ein: Gast und sein Cookie-Geheimnis – oder null, wenn ungültig, abgelaufen, verbraucht oder fremd.
     *
     * @return array{Guest, string}|null
     */
    public function redeem(Wedding $wedding, #[SensitiveParameter] string $token): ?array
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
            return null;
        }

        $entry = $this->em
            ->getRepository(GuestReentryToken::class)
            ->findOneBy(['tokenHash' => GuestReentryToken::hash($token)]);
        $now = $this->now();
        $guest = $entry?->getGuest();
        if (null === $entry || null === $guest || !$entry->isRedeemableAt($now)) {
            return null;
        }

        $secret = $entry->openSecret($token);
        if (
            $guest->getWedding() !== $wedding
            || !$guest->hasAccess()
            || null === $secret
            || !$guest->matchesSecret($secret)
        ) {
            return null;
        }

        $entry->redeem($now);
        $this->em->flush();

        return [$guest, $secret];
    }

    /** Abgelaufene oder eingelöste Codes entfernen; liefert die Anzahl. */
    public function purge(): int
    {
        return (int) $this->em
            ->createQueryBuilder()
            ->delete(GuestReentryToken::class, 't')
            ->where('t.expiresAt <= :now OR t.usedAt IS NOT NULL')
            ->setParameter('now', $this->now())
            ->getQuery()
            ->execute();
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
