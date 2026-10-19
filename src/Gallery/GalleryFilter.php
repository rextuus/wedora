<?php

declare(strict_types=1);

namespace App\Gallery;

enum GalleryFilter: string
{
    case All = 'alle';
    case Photos = 'fotos';
    case Videos = 'videos';
    case Guestbook = 'gaestebuch';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Alle',
            self::Photos => 'Fotos',
            self::Videos => 'Videos',
            self::Guestbook => 'Gästebuch',
        };
    }
}
