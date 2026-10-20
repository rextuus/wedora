<?php

declare(strict_types=1);

namespace App\Wedding;

/**
 * Gemeinsamer Look für alle Fotos und Videos der Hochzeit – nur in der Anzeige (CSS-Filter),
 * Originale, Downloads und Export bleiben unverändert.
 */
enum PhotoLook: string
{
    case Natural = 'natural';
    case Warm = 'warm';
    case Light = 'light';
    case Film = 'film';
    case Mono = 'mono';

    public function label(): string
    {
        return match ($this) {
            self::Natural => 'Natürlich (wie aufgenommen)',
            self::Warm => 'Warm & weich',
            self::Light => 'Hell & luftig',
            self::Film => 'Film',
            self::Mono => 'Schwarzweiß',
        };
    }
}
