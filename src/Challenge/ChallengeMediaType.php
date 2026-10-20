<?php

declare(strict_types=1);

namespace App\Challenge;

use App\Media\MediaType;

/**
 * Art des Beitrags zu einer Aufgabe. „Umfrage“ ist die Aufgabe „Wer von beiden?“: Statt hochzuladen
 * beantworten die Gäste die Fragen der Hochzeit; erledigt ist sie mit der letzten Antwort.
 * „Foto mit gezeichnetem Gast“: ein Foto mit einem zugelosten Partner von der Gästewand.
 */
enum ChallengeMediaType: string
{
    case Photo = 'photo';
    case Video = 'video';
    case Any = 'any';
    case Poll = 'poll';
    /** Gemeinsames Foto mit einem zugelosten Gast von der Gästewand (siehe PortraitBuddy). */
    case Buddy = 'buddy';

    public function accepts(MediaType $type): bool
    {
        return match ($this) {
            self::Any => true,
            self::Buddy => MediaType::Photo === $type,
            default => $this->value === $type->value,
        };
    }

    public function isBuddy(): bool
    {
        return self::Buddy === $this;
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
            self::Buddy => 'Foto mit gezeichnetem Gast',
        };
    }
}
