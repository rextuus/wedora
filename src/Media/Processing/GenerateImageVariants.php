<?php

declare(strict_types=1);

namespace App\Media\Processing;

/** Anzeige-Varianten eines Fotos bzw. Video-Standbilds vorberechnen (siehe ImageVariants). */
final readonly class GenerateImageVariants
{
    public function __construct(
        public string $mediaId,
    ) {}
}
