<?php

declare(strict_types=1);

namespace App\Challenge;

use App\Challenge\Entity\Challenge;
use App\Guest\Entity\Guest;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sonderregeln fürs Hochladen zu einer Aufgabe: Zur Umfrage lädt man nichts hoch (eigene Seite),
 * zu „Foto mit gezeichnetem Gast“ nur mit zugelostem Partner, mit Glücksrad nur zu gezogenen Aufgaben.
 */
final readonly class ChallengeUploadRules
{
    public function __construct(
        private PortraitBuddy $buddies,
        private ChallengeWheel $wheel,
        private UrlGeneratorInterface $urls,
    ) {}

    /** Wohin die Upload-Seite stattdessen führt (Umfrage bzw. Aufgabe zum Zulosen); null = normal hochladen. */
    public function detour(Guest $guest, Challenge $challenge): ?string
    {
        $slug = $guest->getWedding()->getSlug();

        return match (true) {
            !$this->wheel->allows($guest, $challenge) => $this->urls->generate('guest_challenges', ['slug' => $slug]),
            $challenge->getMediaType()->isPoll() => $this->urls->generate('guest_poll', ['slug' => $slug]),
            null !== $this->rejection($guest, $challenge) => $this->urls->generate('guest_challenge', [
                'slug' => $slug,
                'id' => $challenge->getId()->toRfc4122(),
            ]),
            default => null,
        };
    }

    /** Grund, warum der Upload nicht starten darf; null = erlaubt (übrige Prüfungen macht UploadService). */
    public function rejection(Guest $guest, Challenge $challenge): ?string
    {
        if (!$this->wheel->allows($guest, $challenge)) {
            return 'Diese Aufgabe musst du zuerst am Glücksrad ziehen.';
        }

        if ($challenge->getMediaType()->isBuddy() && null === $this->buddies->current($guest, $challenge)) {
            return 'Lass dir zuerst bei der Aufgabe einen Partner zulosen.';
        }

        return null;
    }
}
