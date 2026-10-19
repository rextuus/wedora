<?php

declare(strict_types=1);

namespace App\Media;

/**
 * Wer einen Beitrag sehen darf – unabhängig davon, ob er veröffentlicht ist.
 */
enum Visibility: string
{
    /** Alle Gäste der Hochzeit (sobald veröffentlicht). */
    case AllGuests = 'all_guests';

    /** Nur Brautpaar/Hochzeitsadministration, z. B. private Videobotschaft. */
    case Couple = 'couple';

    /** Nur der hochladende Gast selbst. */
    case UploaderOnly = 'uploader_only';
}
