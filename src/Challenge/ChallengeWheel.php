<?php

declare(strict_types=1);

namespace App\Challenge;

use App\Challenge\Entity\Challenge;
use App\Challenge\Entity\ChallengeDraw;
use App\Challenge\Repository\ChallengeDrawRepository;
use App\Challenge\Repository\ChallengeRepository;
use App\Guest\Entity\Guest;
use App\Poll\CouplePoll;
use App\Wedding\Entity\Wedding;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Glücksrad (einstellbar je Hochzeit): Gäste sehen nicht alle Aufgaben, sondern ziehen immer eine.
 * Erst wenn sie erledigt (oder ihr Zeitfenster vorbei) ist, darf weitergedreht werden. First und Last Drink
 * sind ausgenommen und immer sichtbar; gezogene Aufgaben bleiben sichtbar. Ohne Glücksrad gilt alles wie bisher.
 */
final class ChallengeWheel implements ResetInterface
{
    /** @var array<string, array<string, true>> gezogene Aufgaben-IDs je Gast (je Anfrage einmal geladen) */
    private array $drawn = [];

    public function __construct(
        private readonly ChallengeRepository $challenges,
        private readonly ChallengeDrawRepository $draws,
        private readonly ChallengeProgress $progress,
        private readonly CouplePoll $poll,
        private readonly WelcomeMission $mission,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {}

    public function reset(): void
    {
        $this->drawn = [];
    }

    /**
     * Eingeschaltete Aufgaben für Gäste; die Umfrage nur, wenn Gäste sie sehen können (z. B. mit mindestens einer Frage).
     *
     * @return list<Challenge>
     */
    public function offered(Wedding $wedding): array
    {
        $poll = $this->poll->isVisibleIn($wedding);

        return array_values(array_filter(
            $this->challenges->forWedding($wedding, true),
            static fn(Challenge $c): bool => $poll || !$c->getMediaType()->isPoll(),
        ));
    }

    /**
     * Was der Gast von den Aufgaben sieht: mit Glücksrad nur First/Last Drink und Gezogenes.
     *
     * @param list<Challenge> $challenges
     *
     * @return list<Challenge>
     */
    public function visible(Guest $guest, array $challenges): array
    {
        return array_values(array_filter($challenges, fn(Challenge $c): bool => $this->allows($guest, $c)));
    }

    /** Darf der Gast die Aufgabe sehen und dazu beitragen? */
    public function allows(Guest $guest, Challenge $challenge): bool
    {
        return (
            !$guest->getWedding()->drawsChallengesByWheel()
            || $this->mission->isDrink($challenge)
            || array_key_exists($challenge->getId()->toRfc4122(), $this->drawnBy($guest))
        );
    }

    /**
     * Die gezogene Aufgabe, die noch offen ist; solange es sie gibt, wird nicht weitergedreht.
     *
     * @param list<Challenge> $challenges
     * @param array<string, true> $done
     */
    public function current(Guest $guest, array $challenges, array $done): ?Challenge
    {
        if (!$guest->getWedding()->drawsChallengesByWheel()) {
            return null;
        }

        $now = $this->now();
        $drawn = $this->drawnBy($guest);
        foreach ($challenges as $challenge) {
            $id = $challenge->getId()->toRfc4122();
            if (
                array_key_exists($id, $drawn)
                && !array_key_exists($id, $done)
                && !$this->mission->isDrink($challenge)
                && !$challenge->hasEndedAt($now)
            ) {
                return $challenge;
            }
        }

        return null;
    }

    /**
     * Was das Rad gerade hergibt: nicht gezogen, nicht erledigt, jetzt machbar, kein First/Last Drink.
     *
     * @param list<Challenge> $challenges
     * @param array<string, true> $done
     *
     * @return list<Challenge>
     */
    public function pool(Guest $guest, array $challenges, array $done): array
    {
        if (!$guest->getWedding()->drawsChallengesByWheel()) {
            return [];
        }

        $now = $this->now();
        $drawn = $this->drawnBy($guest);

        return array_values(array_filter(
            $challenges,
            fn(Challenge $c): bool => (
                !array_key_exists($c->getId()->toRfc4122(), $drawn)
                && !array_key_exists($c->getId()->toRfc4122(), $done)
                && !$this->mission->isDrink($c)
                && $c->acceptsAt($now)
            ),
        ));
    }

    /**
     * Dreht das Rad: liefert die neu gezogene Aufgabe – oder die noch offene, falls es eine gibt.
     * Null, wenn das Rad aus oder nichts mehr zu ziehen ist. Doppelt abgeschickt zieht nicht zweimal.
     */
    public function spin(Guest $guest): ?Challenge
    {
        if (!$guest->getWedding()->drawsChallengesByWheel()) {
            return null;
        }

        return $this->em->wrapInTransaction(function () use ($guest): ?Challenge {
            // Je Gast nacheinander: zwei gleichzeitige Drehungen sollen nicht zwei Aufgaben ziehen.
            $this->em->lock($guest, LockMode::PESSIMISTIC_WRITE);
            $this->reset();
            $challenges = $this->offered($guest->getWedding());
            $done = $this->progress->completedBy($guest, $challenges);

            $open = $this->current($guest, $challenges, $done);
            if (null !== $open) {
                return $open;
            }

            $pool = $this->pool($guest, $challenges, $done);
            if ([] === $pool) {
                return null;
            }

            $drawn = $pool[random_int(0, count($pool) - 1)];
            $this->em->persist(new ChallengeDraw($guest, $drawn, $this->now()));
            $this->em->flush();
            $this->reset();

            return $drawn;
        });
    }

    /**
     * Erledigte unter den sichtbaren Aufgaben (mit Glücksrad zählen nur gezogene).
     *
     * @param array<string, true> $done
     * @param list<Challenge> $challenges
     *
     * @return array<string, true>
     */
    public static function doneAmong(array $done, array $challenges): array
    {
        return array_intersect_key(
            $done,
            array_flip(array_map(static fn(Challenge $c): string => $c->getId()->toRfc4122(), $challenges)),
        );
    }

    /**
     * @return array<string, true>
     */
    private function drawnBy(Guest $guest): array
    {
        $key = $guest->getId()->toRfc4122();
        if (!array_key_exists($key, $this->drawn)) {
            $this->drawn[$key] = [];
            foreach ($this->draws->of($guest) as $draw) {
                $this->drawn[$key][$draw->getChallenge()->getId()->toRfc4122()] = true;
            }
        }

        return $this->drawn[$key];
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
