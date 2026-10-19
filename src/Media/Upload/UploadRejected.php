<?php

declare(strict_types=1);

namespace App\Media\Upload;

use RuntimeException;

/**
 * Fachliche Ablehnung (Limit, Format, Kontingent). Die Meldung ist für Gäste gedacht.
 */
final class UploadRejected extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
