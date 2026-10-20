<?php

declare(strict_types=1);

namespace App\Wedding;

/** Oben auf der Startseite der Gäste: Mosaik der neuesten Beiträge, festes Titelbild oder nichts. */
enum CoverMode: string
{
    case Latest = 'latest';
    case Fixed = 'fixed';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Latest => 'Mosaik der neuesten Fotos und Videos',
            self::Fixed => 'Festes Bild (z. B. vom Brautpaar)',
            self::None => 'Kein Bild',
        };
    }
}
