<?php

declare(strict_types=1);

namespace App\Media;

enum ExportStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
