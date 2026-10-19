<?php

declare(strict_types=1);

namespace App\Media\Export;

final readonly class BuildExport
{
    public function __construct(
        public string $exportId,
    ) {}
}
