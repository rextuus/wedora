<?php

declare(strict_types=1);

namespace App\Challenge;

use App\Challenge\Entity\BuddyAssignment;
use App\Challenge\Entity\Challenge;
use App\Challenge\Repository\BuddyAssignmentRepository;
use App\Guest\Entity\Guest;
use App\Guest\Portrait\PortraitWall;
use App\Media\Entity\Media;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * „Foto mit gezeichnetem Gast“: lost einen Partner von der Gästewand zu, ohne jemanden zu überrennen.
 *
 * Regeln (je Aufgabe einstellbar): Ein Partner kommt in höchstens `buddyLimit` Fotos vor und hat nach einem
 * Foto bzw. solange ihn jemand sucht `buddyPauseMinutes` Pause. Bevorzugt wird, wer am seltensten dran war.
 * Mitmachen kann nur, wer sein eigenes Porträt auch zeigt – sonst dürfte er das des Partners nicht sehen.
 */
final readonly class PortraitBuddy
{
    /** So lange hat man Zeit, den Partner zu finden; danach verfällt die Zulosung. */
    public const int SEARCH_MINUTES = 20;

    public function __construct(
        private EntityManagerInterface $em,
        private BuddyAssignmentRepository $assignments,
        private PortraitWall $wall,
        private ClockInterface $clock,
    ) {}

    public function status(Guest $seeker, Challenge $challenge): BuddyStatus
    {
        if (
            !$this->wall->isOfferedIn($seeker->getWedding())
            || !$seeker->hasPortrait()
            || !$seeker->isPortraitShared()
        ) {
            return BuddyStatus::NeedsPortrait;
        }

        if (null !== $this->current($seeker, $challenge)) {
            return BuddyStatus::Searching;
        }

        return [] === $this->candidates($seeker, $challenge) ? BuddyStatus::NobodyFree : BuddyStatus::Ready;
    }

    /**
     * Stand je Buddy-Aufgabe (für die Aufgabenliste).
     *
     * @param list<Challenge> $challenges
     *
     * @return array<string, string> Aufgaben-ID → BuddyStatus-Wert
     */
    public function statuses(Guest $seeker, array $challenges): array
    {
        $statuses = [];
        foreach ($challenges as $challenge) {
            if ($challenge->getMediaType()->isBuddy()) {
                $statuses[$challenge->getId()->toRfc4122()] = $this->status($seeker, $challenge)->value;
            }
        }

        return $statuses;
    }

    /**
     * Daten für die Aufgabenseite; null bei anderen Aufgaben.
     *
     * @return array{status: string, assignment: BuddyAssignment|null, minutes: int}|null
     */
    public function view(Guest $seeker, Challenge $challenge): ?array
    {
        if (!$challenge->getMediaType()->isBuddy()) {
            return null;
        }

        return [
            'status' => $this->status($seeker, $challenge)->value,
            'assignment' => $this->current($seeker, $challenge),
            'minutes' => self::SEARCH_MINUTES,
        ];
    }

    /** Offene Zulosung des Gastes (nicht beendet, nicht abgelaufen). */
    public function current(Guest $seeker, Challenge $challenge): ?BuddyAssignment
    {
        return $this->assignments->open($seeker, $challenge, $this->searchSince());
    }

    /** Partner zulosen (oder die offene Zulosung behalten); null, wenn gerade niemand frei ist. */
    public function assign(Guest $seeker, Challenge $challenge): ?BuddyAssignment
    {
        if (BuddyStatus::NeedsPortrait === $this->status($seeker, $challenge)) {
            return null;
        }

        $current = $this->current($seeker, $challenge);
        if (null !== $current) {
            return $current;
        }

        $candidates = $this->candidates($seeker, $challenge);
        if ([] === $candidates) {
            return null;
        }

        // Bevorzugt, wer am seltensten dran war; unter Gleichen zufällig.
        $fewest = min(array_column($candidates, 'photos'));
        $pool = array_values(array_filter($candidates, static fn(array $c): bool => $c['photos'] === $fewest));
        $assignment = new BuddyAssignment($challenge, $seeker, $pool[array_rand($pool)]['guest'], $this->now());
        $this->em->persist($assignment);
        $this->em->flush();

        return $assignment;
    }

    /** „Jemand anderen“: offene Zulosung beenden (der Partner bekommt dadurch keine Pause). */
    public function skip(Guest $seeker, Challenge $challenge): void
    {
        $this->current($seeker, $challenge)?->skip($this->now());
        $this->em->flush();
    }

    /** Neues Foto zur Aufgabe: hakt die offene Zulosung des Fotografen ab. */
    public function complete(Media $media): void
    {
        $challenge = $media->getChallenge();
        $seeker = $media->getUploaderGuest();
        if (null === $challenge || null === $seeker || !$challenge->getMediaType()->isBuddy()) {
            return;
        }

        $assignment = $this->current($seeker, $challenge);
        if (null === $assignment) {
            return;
        }

        $assignment->completeWith($media, $this->now());
        // Ohne eigene Beschreibung: beide Namen und ein Spruch („Karl und Mareike – Dreamteam“).
        if (null === $media->getCaption()) {
            $media->changeCaption(BuddyCaption::for($seeker, $assignment->getPartner()));
        }

        $this->em->flush();
    }

    /**
     * Freie Partner: auf der Gästewand, nicht man selbst, noch nicht zusammen fotografiert, unter dem Limit,
     * nicht in der Pause.
     *
     * @return list<array{guest: Guest, photos: int}>
     */
    private function candidates(Guest $seeker, Challenge $challenge): array
    {
        $photos = $this->assignments->photosPerPartner($challenge);
        $busy = $this->assignments->busyPartners(
            $challenge,
            $this->searchSince(),
            $this->now()->modify(sprintf('-%d minutes', $challenge->getBuddyPauseMinutes())),
        );
        $alreadyWith = $this->assignments->partnersOf($seeker, $challenge);

        $candidates = [];
        foreach ($this->wall->guests($seeker->getWedding()) as $guest) {
            $id = $guest->getId()->toRfc4122();
            if (
                $guest === $seeker
                || array_key_exists($id, $busy)
                || array_key_exists($id, $alreadyWith)
                || ($photos[$id] ?? 0) >= $challenge->getBuddyLimit()
            ) {
                continue;
            }

            $candidates[] = ['guest' => $guest, 'photos' => $photos[$id] ?? 0];
        }

        return $candidates;
    }

    private function searchSince(): DateTimeImmutable
    {
        return $this->now()->modify(sprintf('-%d minutes', self::SEARCH_MINUTES));
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
