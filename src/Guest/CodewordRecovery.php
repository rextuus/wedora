<?php

declare(strict_types=1);

namespace App\Guest;

use App\Guest\Entity\Guest;
use App\Wedding\Entity\Wedding;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Ein Wiederherstellungs-Versuch („Ich war schon dabei“): höchstens einer pro Name alle 15 Minuten und fünf pro
 * Gerät. Bei Erfolg bekommt der Gast ein neues Geheimnis – ältere Geräte sind danach abgemeldet.
 */
final readonly class CodewordRecovery
{
    public function __construct(
        private Codeword $codewords,
        private EntityManagerInterface $em,
        #[Target('guest_codeword_name')]
        private RateLimiterFactoryInterface $perName,
        #[Target('guest_codeword_device')]
        private RateLimiterFactoryInterface $perDevice,
    ) {}

    /**
     * @return array{Guest, string}|string Gast und neues Geheimnis – oder die Meldung für den Gast
     */
    public function attempt(Wedding $wedding, string $name, string $choice, ?string $ip): array|string
    {
        $prefix = $wedding->getId()->toRfc4122() . '|';
        $device = $this->perDevice->create($prefix . ($ip ?? 'unbekannt'))->consume();
        $limit = $this->perName->create($prefix . Codeword::normalize($name))->consume();
        if (!$device->isAccepted() || !$limit->isAccepted()) {
            $retry = max($device->getRetryAfter(), $limit->getRetryAfter());

            return sprintf(
                'Zu viele Versuche. Probier es ab %s Uhr noch einmal.',
                $retry->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('H:i'),
            );
        }

        foreach ($this->codewords->choicesFor($wedding, $name)['guests'] as $guest) {
            if (hash_equals((string) $guest->getCodeword(), $choice)) {
                $secret = $guest->rotateSecret();
                $this->em->flush();

                return [$guest, $secret];
            }
        }

        return 'Das war leider nicht dein Codewort. Der nächste Versuch für diesen Namen geht in 15 Minuten.';
    }
}
