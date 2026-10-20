<?php

declare(strict_types=1);

namespace App\Challenge;

use App\Challenge\Entity\Challenge;
use App\Challenge\Repository\ChallengeRepository;
use App\Wedding\Entity\Wedding;
use App\Wedding\ModuleType;
use DateTimeImmutable;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Die Aufgabe, auf die neue Gäste direkt nach dem Beitritt hingewiesen werden: First Drink
 * (und als Gegenstück Last Drink). Erkannt am Motiv, also auch bei leicht umbenannten Aufgaben.
 *
 * Seiten fragen das oft mehrfach ab (z. B. je Kachel); die Aufgaben einer Hochzeit werden deshalb je Anfrage
 * einmal geladen und danach verworfen (ResetInterface).
 */
final class WelcomeMission implements ResetInterface
{
    /** @var array<string, list<Challenge>> */
    private array $enabled = [];

    public function __construct(
        private readonly ChallengeRepository $challenges,
    ) {}

    public function reset(): void
    {
        $this->enabled = [];
    }

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

        $this->enabled[$wedding->getId()->toRfc4122()] ??= $this->challenges->forWedding($wedding, true);
        foreach ($this->enabled[$wedding->getId()->toRfc4122()] as $challenge) {
            if (ChallengeMotif::for($challenge->getTitle()) === $motif && $available($challenge)) {
                return $challenge;
            }
        }

        return null;
    }
}
