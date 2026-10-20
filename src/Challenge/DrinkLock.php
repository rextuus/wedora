<?php

declare(strict_types=1);

namespace App\Challenge;

use App\Guest\Entity\Guest;
use App\Media\Entity\Media;
use Symfony\Contracts\Service\ResetInterface;

/**
 * First- bzw. Last-Drink-Videos anderer Gäste bleiben gesperrt, bis der Gast selbst eins zu derselben Aufgabe
 * gedreht hat (eigenes First Drink → alle First Drinks): in Listen nur als verwischte Mini-Vorschau,
 * ohne Abruf von Bild, Video oder Detailseite.
 * Merkt sich Ergebnisse für die Dauer einer Anfrage.
 */
final class DrinkLock implements ResetInterface
{
    /** Variante, die auch gesperrt ausgeliefert wird (24 × 24 Pixel, stark verwischt). */
    public const string PREVIEW_VARIANT = 'image/tiny';

    /** @var array<string, list<string>> Hochzeit → IDs der beiden Drink-Aufgaben */
    private array $challengeIds = [];

    /** @var array<string, array{first: Media|null, last: Media|null}> Gast → eigene Drink-Videos */
    private array $own = [];

    public function __construct(
        private readonly WelcomeMission $mission,
        private readonly DrinkComparison $comparison,
    ) {}

    public function isLocked(Guest $viewer, Media $media): bool
    {
        $challenge = $media->getChallenge();
        if (null === $challenge || $media->getUploaderGuest()?->getId()->equals($viewer->getId()) === true) {
            return false;
        }

        $slot = array_search($challenge->getId()->toRfc4122(), $this->drinkChallengeIds($viewer), true);
        if (false === $slot) {
            return false;
        }

        $this->own[$viewer->getId()->toRfc4122()] ??= $this->comparison->own($viewer);

        return null === $this->own[$viewer->getId()->toRfc4122()][0 === $slot ? 'first' : 'last'];
    }

    public function reset(): void
    {
        $this->challengeIds = [];
        $this->own = [];
    }

    /**
     * @return list<string>
     */
    private function drinkChallengeIds(Guest $viewer): array
    {
        $wedding = $viewer->getWedding();

        return $this->challengeIds[$wedding->getId()->toRfc4122()] ??= array_map(
            static fn($c): string => $c->getId()->toRfc4122(),
            $this->mission->drinkPair($wedding) ?? [],
        );
    }
}
