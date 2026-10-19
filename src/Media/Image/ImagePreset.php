<?php

declare(strict_types=1);

namespace App\Media\Image;

/**
 * Feste Bildvarianten. Nur diese Transformationen sind von außen anforderbar,
 * damit niemand beliebig große oder teure Varianten erzeugen kann.
 */
enum ImagePreset: string
{
    /** Galerie-Kachel, Feed. */
    case Thumb = 'thumb';

    /** Großansicht. */
    case Display = 'display';

    /** Winzige, weichgezeichnete Vorschau (≈ 1 KB), solange das eigentliche Bild lädt („Blur-up“). */
    case Tiny = 'tiny';

    /**
     * @return list<string>
     */
    public function processingOptions(): array
    {
        return match ($this) {
            self::Thumb => ['rs:fit:720:720', 'q:75'],
            self::Display => ['rs:fit:1920:1920', 'q:82'],
            self::Tiny => ['rs:fill:24:24', 'bl:1', 'q:40'],
        };
    }
}
