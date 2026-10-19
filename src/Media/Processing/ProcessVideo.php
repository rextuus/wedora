<?php

declare(strict_types=1);

namespace App\Media\Processing;

/**
 * Auftrag an den Worker: Video umwandeln und Standbild erzeugen.
 */
final readonly class ProcessVideo
{
    public function __construct(
        public string $mediaId,
    ) {}
}
