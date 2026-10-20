<?php

declare(strict_types=1);

namespace App\Wedding\Host;

/** Wie der Hinweis über den Gastgeber spricht („Sprich ihn gern an“). Ohne Angabe neutral. */
enum HostPronoun: string
{
    case He = 'er';
    case She = 'sie';

    /** Akkusativ für „Sprich … gern an“. */
    public function accusative(): string
    {
        return match ($this) {
            self::He => 'ihn',
            self::She => 'sie',
        };
    }
}
