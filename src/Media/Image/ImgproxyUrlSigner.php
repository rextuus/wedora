<?php

declare(strict_types=1);

namespace App\Media\Image;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Erzeugt signierte imgproxy-Pfade (HMAC-SHA256 über Salt + Pfad).
 *
 * Die Signatur verhindert frei gewählte Transformationen und Quellen; sie ersetzt
 * keine Zugriffsprüfung, weil signierte URLs nicht ablaufen. imgproxy bleibt daher intern.
 */
final readonly class ImgproxyUrlSigner
{
    private string $key;
    private string $salt;

    public function __construct(#[SensitiveParameter] string $hexKey, #[SensitiveParameter] string $hexSalt)
    {
        $this->key = self::decodeHex($hexKey, 'IMGPROXY_KEY');
        $this->salt = self::decodeHex($hexSalt, 'IMGPROXY_SALT');
    }

    /**
     * @param list<string> $processingOptions z. B. ['rs:fill:600:600', 'q:80']
     */
    public function sign(string $sourceUrl, array $processingOptions, string $extension = 'webp'): string
    {
        $path = '/';
        if ([] !== $processingOptions) {
            $path .= implode('/', $processingOptions) . '/';
        }

        $path .= self::base64Url($sourceUrl) . '.' . $extension;

        return $this->signPath($path);
    }

    /**
     * @param string $path Pfad inkl. führendem Slash, ohne Signatur
     */
    public function signPath(string $path): string
    {
        return '/' . self::base64Url(hash_hmac('sha256', $this->salt . $path, $this->key, true)) . $path;
    }

    private static function decodeHex(string $hex, string $name): string
    {
        $binary = '' !== $hex && 0 === (strlen($hex) % 2) && ctype_xdigit($hex) ? hex2bin($hex) : false;
        if (false === $binary) {
            throw new InvalidArgumentException(sprintf('%s muss ein nicht-leerer Hex-String sein.', $name));
        }

        return $binary;
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
