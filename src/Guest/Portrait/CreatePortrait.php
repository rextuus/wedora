<?php

declare(strict_types=1);

namespace App\Guest\Portrait;

final readonly class CreatePortrait
{
    public function __construct(
        public string $guestId,
    ) {}
}
