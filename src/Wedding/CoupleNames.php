<?php

declare(strict_types=1);

namespace App\Wedding;

use App\Poll\PollChoice;
use App\Wedding\Entity\Wedding;

/**
 * Die beiden Namen des Paares (Umfrage, Porträt-Fragen), abgeleitet aus dem Hochzeitstitel („Larissa & Tim“, „Mia und Ben“).
 * Passt der Titel nicht in dieses Muster, gibt es neutrale Platzhalter.
 */
final readonly class CoupleNames
{
    public function __construct(
        public string $first,
        public string $second,
    ) {}

    public static function of(Wedding $wedding): self
    {
        return self::fromTitle($wedding->getTitle());
    }

    public static function fromTitle(string $title): self
    {
        $parts = preg_split('/\s+(?:&|\+|und|and)\s+/iu', trim($title), 2);
        if (is_array($parts) && 2 === count($parts) && '' !== trim($parts[0]) && '' !== trim($parts[1])) {
            return new self(trim($parts[0]), trim($parts[1]));
        }

        return new self('Person 1', 'Person 2');
    }

    /** „L & T“ – null, wenn der Titel keine zwei Namen hergab (Platzhalter). */
    public function initials(): ?string
    {
        if ('Person 1' === $this->first && 'Person 2' === $this->second) {
            return null;
        }

        return mb_strtoupper(mb_substr($this->first, 0, 1)) . ' & ' . mb_strtoupper(mb_substr($this->second, 0, 1));
    }

    public function name(PollChoice $choice): string
    {
        return PollChoice::First === $choice ? $this->first : $this->second;
    }
}
