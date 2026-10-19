<?php

declare(strict_types=1);

namespace App\Media\Upload;

use App\Media\MediaType;
use App\Media\Visibility;
use App\Wedding\ModuleType;

/**
 * Angaben, mit denen ein Gast einen Upload ankündigt.
 */
final readonly class UploadRequest
{
    public function __construct(
        public MediaType $type,
        public int $bytes,
        public Visibility $visibility = Visibility::AllGuests,
        public ModuleType $module = ModuleType::Gallery,
        public ?string $challengeId = null,
        public ?string $caption = null,
    ) {}
}
