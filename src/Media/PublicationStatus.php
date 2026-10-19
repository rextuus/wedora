<?php

declare(strict_types=1);

namespace App\Media;

enum PublicationStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Rejected = 'rejected';
}
