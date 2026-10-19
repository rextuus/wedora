<?php

declare(strict_types=1);

namespace App\Media\Upload;

use RuntimeException;

/**
 * Der Client sendet ein Stück an der falschen Stelle; er soll beim gemeldeten Stand weitermachen.
 */
final class UploadOffsetMismatch extends RuntimeException
{
    public function __construct(
        public readonly int $expectedOffset,
    ) {
        parent::__construct('Upload-Offset passt nicht.');
    }
}
