<?php

declare(strict_types=1);

namespace App\Account;

/**
 * Einzelrechte der Hochzeitsadministration. Werden von der Plattformadministration
 * explizit vergeben; es gibt keine impliziten Rechte.
 */
enum WeddingPermission: string
{
    /** Veröffentlichte Beiträge für alle Gäste und für das Brautpaar sehen. */
    case ViewContent = 'view_content';

    /** Beiträge freigeben, ablehnen, ausblenden (inkl. unveröffentlichter). */
    case Moderate = 'moderate';

    /** Titel, Datum, Module, Moderationsmodus, Limits innerhalb der Plattformgrenzen. */
    case Configure = 'configure';

    /** Offenen Link, Passwort, Einladungen, Gäste sperren. */
    case ManageAccess = 'manage_access';

    case Export = 'export';

    /** Archivieren, Aufbewahrung, Löschung beantragen. */
    case ManageLifecycle = 'manage_lifecycle';

    /**
     * Rechte, die die Plattformadministration ohne Mitgliedschaft hat: Verwaltung ja,
     * Inhalte nein (Datenschutz: Plattformbetrieb sieht keine Gästefotos).
     *
     * @return list<self>
     */
    public static function platformAdminDefaults(): array
    {
        return [self::Configure, self::ManageAccess, self::ManageLifecycle];
    }
}
