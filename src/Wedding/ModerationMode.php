<?php

declare(strict_types=1);

namespace App\Wedding;

/**
 * Wie neue Beiträge veröffentlicht werden. Unabhängig von der Sichtbarkeit.
 */
enum ModerationMode: string
{
    /** Sofort veröffentlicht. */
    case Immediate = 'immediate';

    /** Erst nach Freigabe durch die Moderation sichtbar. */
    case Approval = 'approval';

    /** Sofort veröffentlicht, Moderation prüft nachträglich. */
    case PostModeration = 'post_moderation';
}
