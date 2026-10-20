<?php

declare(strict_types=1);

namespace App\Media\Storage;

/**
 * Feste Dateinamen pro Medium. Weitere Varianten (z. B. HLS) kämen hier hinzu.
 */
enum MediaFile: string
{
    /** Unverändertes Original des Gastes. */
    case Original = 'original';

    /** Transkodiertes Wiedergabevideo (H.264/AAC, max. 720p, faststart). */
    case Video = 'video.mp4';

    /** Standbild des Videos; Quelle für Vorschaubilder via imgproxy. */
    case Poster = 'poster.jpg';

    /** Angeglichene Fassung eines Fotos („Fotos automatisch angleichen“); Quelle für die Anzeige statt des Originals. */
    case Enhanced = 'enhanced.jpg';
}
