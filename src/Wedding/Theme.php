<?php

declare(strict_types=1);

namespace App\Wedding;

/**
 * Gestaltung der Gastseiten: Farben, Schriften und Art der Zeichnungen (CSS-Klasse theme-<wert> am <body>).
 */
enum Theme: string
{
    /** Elfenbein und Salbei, Serifenschrift, feine botanische Linien. */
    case Botanical = 'botanical';

    /** Wie eine Einladungskarte: Ziegelrot auf Rosé, Handschrift, gekritzelte Zeichnungen. */
    case Handwritten = 'handwritten';

    public function label(): string
    {
        return match ($this) {
            self::Botanical => 'Botanisch',
            self::Handwritten => 'Handgeschrieben',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Botanical => 'Elfenbein & Salbei, feine Zweige',
            self::Handwritten => 'Ziegelrot auf Rosé, Handschrift, Kritzeleien',
        };
    }

    /** Hintergrundfarbe für Browserleiste und App-Manifest. */
    public function color(): string
    {
        return match ($this) {
            self::Botanical => '#fbf9f6',
            self::Handwritten => '#f0e6e2',
        };
    }
}
