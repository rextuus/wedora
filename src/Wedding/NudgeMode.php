<?php

declare(strict_types=1);

namespace App\Wedding;

/** Wann die kleine Figur „Zu viel am Handy?“ auftaucht; erst wer sie antippt, sieht den Hinweis. */
enum NudgeMode: string
{
    case Time = 'time';
    case Activity = 'activity';
    case Off = 'off';

    public function label(): string
    {
        return match ($this) {
            self::Time => 'Nach einiger Zeit in der App (Minuten)',
            self::Activity => 'Nach vielen Beiträgen innerhalb einer Stunde (Anzahl)',
            self::Off => 'Gar nicht',
        };
    }

    public function defaultThreshold(): int
    {
        return match ($this) {
            self::Time => 20,
            self::Activity => 10,
            self::Off => 0,
        };
    }
}
