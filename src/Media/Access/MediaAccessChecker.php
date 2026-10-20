<?php

declare(strict_types=1);

namespace App\Media\Access;

/**
 * Prüft bei jeder Medienanfrage (Video, Bild, Vorschau), ob der Betrachter das Medium sehen darf.
 *
 * Wird in Phase 2 durch die Domänen-Autorisierung (Sichtbarkeit, Veröffentlichung,
 * widerrufene Einladungen) implementiert.
 */
interface MediaAccessChecker
{
    /**
     * @param string $variant angefragte Variante (z. B. „video.mp4“, „image/display“, „image/tiny“)
     */
    public function canView(string $subject, string $mediaId, string $variant = 'image/display'): bool;
}
