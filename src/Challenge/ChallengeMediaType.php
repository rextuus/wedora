<?php

declare(strict_types=1);

namespace App\Challenge;

use App\Media\MediaType;

/**
 * Art des Beitrags zu einer Aufgabe. „Umfrage“ ist die Aufgabe „Wer von beiden?“: Statt hochzuladen
 * beantworten die Gäste die Fragen der Hochzeit; erledigt ist sie mit der letzten Antwort.
 */
enum ChallengeMediaType: string
{
    case Photo = 'photo';
    case Video = 'video';
    case Any = 'any';
    case Poll = 'poll';

    public function accepts(MediaType $type): bool
    {
        return self::Any === $this || $this->value === $type->value;
    }

    public function isPoll(): bool
    {
        return self::Poll === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Photo => 'Foto',
            self::Video => 'Video',
            self::Any => 'Foto oder Video',
            self::Poll => 'Umfrage',
        };
    }
}
