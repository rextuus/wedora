<?php

declare(strict_types=1);

namespace App\Wedding\Host;

/**
 * Bilder, die das Brautpaar bzw. die Verwaltung selbst hochlädt (keine Gästebeiträge):
 * Foto des Gastgebers, Foto des Servers und das feste Titelbild der Startseite.
 */
enum WeddingPicture: string
{
    case Host = 'hosts';
    case Server = 'servers';
    case Cover = 'covers';

    /** ffmpeg-Filter: Gastgeber quadratisch, Server und Titelbild im Originalformat verkleinert. */
    public function filter(): string
    {
        return match ($this) {
            self::Host => 'scale=320:320:force_original_aspect_ratio=increase,crop=320:320',
            self::Server => "scale='min(1200,iw)':-2",
            self::Cover => "scale='min(1600,iw)':-2",
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Host => 'Foto des Gastgebers',
            self::Server => 'Foto des Servers',
            self::Cover => 'Titelbild',
        };
    }
}
