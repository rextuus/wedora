<?php

declare(strict_types=1);

namespace App\Guest\Portrait;

enum PortraitStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
