<?php

declare(strict_types=1);

namespace App\Challenge;

use App\Challenge\Entity\Challenge;
use App\Challenge\Repository\ChallengeRepository;
use App\Wedding\Entity\Wedding;
use App\Wedding\ModuleType;
use DateTimeImmutable;

/**
 * Die Aufgabe, auf die neue Gäste direkt nach dem Beitritt hingewiesen werden: First Drink
 * (und als Gegenstück Last Drink). Erkannt am Motiv, also auch bei leicht umbenannten Aufgaben.
 */
final readonly class WelcomeMission
{
    public function __construct(
        private ChallengeRepository $challenges,
    ) {}

    public function firstDrink(Wedding $wedding, DateTimeImmutable $now): ?Challenge
    {
        return $this->find($wedding, 'glasses', static fn(Challenge $c): bool => $c->acceptsAt($now));
    }

    /** Darf schon vor seinem Zeitfenster gezeigt werden („ab 23 Uhr“). */
    public function lastDrink(Wedding $wedding, DateTimeImmutable $now): ?Challenge
    {
        return $this->find($wedding, 'moon', static fn(Challenge $c): bool => !$c->hasEndedAt($now));
    }

    /**
     * Beide Aufgaben für den Vorher-nachher-Vergleich, unabhängig vom Zeitfenster.
     *
     * @return array{Challenge, Challenge}|null [First Drink, Last Drink]
     */
    public function drinkPair(Wedding $wedding): ?array
    {
        ['first' => $first, 'last' => $last] = $this->drinks($wedding);

        return null === $first || null === $last ? null : [$first, $last];
    }

    /**
     * First und Last Drink einzeln (z. B. für Links in der Erklär-Box), unabhängig vom Zeitfenster.
     *
     * @return array{first: Challenge|null, last: Challenge|null}
     */
    public function drinks(Wedding $wedding): array
    {
        $always = static fn(Challenge $c): bool => true;

        return [
            'first' => $this->find($wedding, 'glasses', $always),
            'last' => $this->find($wedding, 'moon', $always),
        ];
    }

    /** First oder Last Drink: davon hat jeder Gast höchstens ein Video, ein neues ersetzt das alte. */
    public function isDrink(Challenge $challenge): bool
    {
        foreach ($this->drinks($challenge->getWedding()) as $drink) {
            if ($drink?->getId()->equals($challenge->getId()) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param callable(Challenge): bool $available
     */
    private function find(Wedding $wedding, string $motif, callable $available): ?Challenge
    {
        if (!$wedding->module(ModuleType::Challenges)->isEnabled()) {
            return null;
        }

        foreach ($this->challenges->forWedding($wedding, true) as $challenge) {
            if (ChallengeMotif::for($challenge->getTitle()) === $motif && $available($challenge)) {
                return $challenge;
            }
        }

        return null;
    }
}
