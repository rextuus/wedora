<?php

declare(strict_types=1);

namespace App\Ai;

final readonly class GeneratedImage
{
    public function __construct(
        public string $bytes,
        public string $mimeType,
    ) {}
}
