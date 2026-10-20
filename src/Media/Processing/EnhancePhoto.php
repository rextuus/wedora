<?php

declare(strict_types=1);

namespace App\Media\Processing;

/** Ein Foto angleichen bzw. – wenn die Hochzeit es nicht mehr will – die angeglichene Fassung entfernen. */
final readonly class EnhancePhoto
{
    public function __construct(
        public string $mediaId,
    ) {}
}
