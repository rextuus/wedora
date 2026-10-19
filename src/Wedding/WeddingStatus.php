<?php

declare(strict_types=1);

namespace App\Wedding;

enum WeddingStatus: string
{
    /** Gäste können beitreten und beitragen. */
    case Active = 'active';

    /** Nur noch lesend; Archivieren löscht keine Medien (Konzept: Lebenszyklus). */
    case Archived = 'archived';
}
