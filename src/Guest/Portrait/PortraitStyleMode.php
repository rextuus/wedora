<?php

declare(strict_types=1);

namespace App\Guest\Portrait;

/** Wer bestimmt den Zeichenstil: der Gast oder der Zufall (jeweils aus den angebotenen Stilen). */
enum PortraitStyleMode: string
{
    case Random = 'random';
    case Choice = 'choice';

    public function label(): string
    {
        return match ($this) {
            self::Random => 'Zufall – Gäste lassen sich überraschen',
            self::Choice => 'Gäste wählen selbst',
        };
    }
}
