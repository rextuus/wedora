<?php

declare(strict_types=1);

namespace App\Media\Access;

final readonly class MediaAccessToken
{
    public function __construct(
        public string $mediaId,
        public string $subject,
        public int $expiresAt,
    ) {}
}
