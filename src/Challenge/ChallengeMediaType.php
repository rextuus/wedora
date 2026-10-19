<?php

declare(strict_types=1);

namespace App\Challenge;

use App\Media\MediaType;

enum ChallengeMediaType: string
{
    case Photo = 'photo';
    case Video = 'video';
    case Any = 'any';

    public function accepts(MediaType $type): bool
    {
        return self::Any === $this || $this->value === $type->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Photo => 'Foto',
            self::Video => 'Video',
            self::Any => 'Foto oder Video',
        };
    }
}
