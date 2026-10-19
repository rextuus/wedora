<?php

declare(strict_types=1);

namespace App\Media;

enum MediaType: string
{
    case Photo = 'photo';
    case Video = 'video';
}
