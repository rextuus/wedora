<?php

declare(strict_types=1);

namespace App\Wedding;

enum ModuleType: string
{
    case Gallery = 'gallery';
    case Guestbook = 'guestbook';
    case Challenges = 'challenges';
}
