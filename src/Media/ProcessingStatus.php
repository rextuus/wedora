<?php

declare(strict_types=1);

namespace App\Media;

/**
 * Technischer Zustand der Datei – unabhängig von Sichtbarkeit und Veröffentlichung.
 */
enum ProcessingStatus: string
{
    /** Hochgeladen, Verarbeitung (Video-Umwandlung) läuft oder wartet. */
    case Processing = 'processing';

    case Ready = 'ready';

    /** Verarbeitung endgültig fehlgeschlagen; Grund steht am Medium. */
    case Failed = 'failed';
}
