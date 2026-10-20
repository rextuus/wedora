<?php

declare(strict_types=1);

namespace App\Poll;

/** Erste oder zweite Person aus dem Hochzeitstitel. */
enum PollChoice: string
{
    case First = 'first';
    case Second = 'second';
}
