<?php

declare(strict_types=1);

namespace App\Social;

enum ReportReason: string
{
    case Inappropriate = 'inappropriate';
    case Privacy = 'privacy';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Inappropriate => 'Unpassender Inhalt',
            self::Privacy => 'Ich möchte nicht darauf zu sehen sein',
            self::Other => 'Anderer Grund',
        };
    }
}
