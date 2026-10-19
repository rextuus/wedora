<?php

declare(strict_types=1);

namespace App\Media\Access;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

/**
 * Kurzlebiges, an Medium und Betrachter gebundenes Token für Medien-URLs.
 *
 * Begrenzt den Schaden weitergegebener Links (Ablauf). Die Berechtigung selbst wird
 * zusätzlich bei jeder Anfrage serverseitig geprüft (MediaAccessChecker).
 */
final readonly class MediaAccessTokenSigner
{
    public function __construct(
        #[SensitiveParameter]
        private string $secret,
        private ClockInterface $clock,
        private int $ttlSeconds = 900,
    ) {
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('Das Medien-Token-Secret muss mindestens 32 Zeichen lang sein.');
        }
    }

    public function issue(string $mediaId, string $subject): string
    {
        $payload = self::base64Url(implode('|', [
            $mediaId,
            $subject,
            (string) ($this->clock->now()->getTimestamp() + $this->ttlSeconds),
        ]));

        return $payload . '.' . $this->signature($payload);
    }

    public function verify(#[SensitiveParameter] string $token): ?MediaAccessToken
    {
        $parts = explode('.', $token);
        if (2 !== count($parts)) {
            return null;
        }

        [$payload, $signature] = $parts;
        if (!hash_equals($this->signature($payload), $signature)) {
            return null;
        }

        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
        if (false === $decoded) {
            return null;
        }

        $fields = explode('|', $decoded);
        if (3 !== count($fields) || !ctype_digit($fields[2])) {
            return null;
        }

        $expiresAt = (int) $fields[2];
        if ($expiresAt < $this->clock->now()->getTimestamp()) {
            return null;
        }

        return new MediaAccessToken($fields[0], $fields[1], $expiresAt);
    }

    private function signature(string $payload): string
    {
        return self::base64Url(hash_hmac('sha256', 'media|' . $payload, $this->secret, true));
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
