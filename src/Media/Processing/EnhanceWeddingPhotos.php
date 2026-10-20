<?php

declare(strict_types=1);

namespace App\Media\Processing;

/** Nach dem Umschalten „Fotos automatisch angleichen“: alle Fotos der Hochzeit nachziehen. */
final readonly class EnhanceWeddingPhotos
{
    public function __construct(
        public string $weddingId,
    ) {}
}
