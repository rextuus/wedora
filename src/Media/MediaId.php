<?php

declare(strict_types=1);

namespace App\Media;

use InvalidArgumentException;
use Stringable;

/**
 * Technische ID eines Mediums (UUID). Wird als Verzeichnisname im Speicher genutzt,
 * daher streng validiert – kein Pfad-Traversal möglich.
 */
final readonly class MediaId implements Stringable
{
    public const string PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public string $value;

    public function __construct(string $value)
    {
        if (1 !== preg_match('/^' . self::PATTERN . '$/D', $value)) {
            throw new InvalidArgumentException('Ungültige Medien-ID.');
        }

        $this->value = $value;
    }

    public static function generate(): self
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return new self(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4)));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
