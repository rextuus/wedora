<?php

declare(strict_types=1);

namespace App\Challenge;

/** Stand eines Gastes bei „Foto mit gezeichnetem Gast“. */
enum BuddyStatus: string
{
    /** Erst eigenes Porträt zeichnen lassen und auf der Gästewand zeigen. */
    case NeedsPortrait = 'needs_portrait';
    /** Es ist jemand frei – „Wen soll ich suchen?“. */
    case Ready = 'ready';
    /** Ein Partner ist zugelost und wird gesucht. */
    case Searching = 'searching';
    /** Gerade ist niemand frei (alle in der Pause oder schon oft genug fotografiert). */
    case NobodyFree = 'nobody_free';
}
