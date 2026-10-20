<?php

declare(strict_types=1);

namespace App\Poll;

use App\Challenge\Entity\Challenge;
use App\Challenge\Repository\ChallengeRepository;
use App\Guest\Entity\Guest;
use App\Poll\Entity\PollQuestion;
use App\Poll\Entity\PollVote;
use App\Poll\Repository\PollQuestionRepository;
use App\Wedding\Entity\Wedding;
use App\Wedding\ModuleType;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Umfrage „Wer von beiden …?“: Gäste schätzen pro Frage ein, wer von den beiden etwas mehr oder weniger macht.
 */
final readonly class CouplePoll
{
    public function __construct(
        private EntityManagerInterface $em,
        private PollQuestionRepository $questions,
        private ChallengeRepository $challenges,
        private ClockInterface $clock,
    ) {}

    /** Die Aufgabe „Wer von beiden?“ der Hochzeit (Art „Umfrage“), auch wenn sie ausgeblendet ist. */
    public function challenge(Wedding $wedding): ?Challenge
    {
        foreach ($this->challenges->forWedding($wedding) as $challenge) {
            if ($challenge->getMediaType()->isPoll()) {
                return $challenge;
            }
        }

        return null;
    }

    /** Für Gäste sichtbar: Umfrage-Aufgabe eingeschaltet, Aufgaben-Modul an und mindestens eine Frage. */
    public function isVisibleIn(Wedding $wedding): bool
    {
        return (
            true === $this->challenge($wedding)?->isEnabled()
            && $wedding->module(ModuleType::Challenges)->isEnabled()
            && [] !== $this->questions->forWedding($wedding)
        );
    }

    /** Abstimmen geht nur im Zeitfenster der Aufgabe (z. B. „ab 18 Uhr“). */
    public function isOpenIn(Wedding $wedding): bool
    {
        return (
            $this->isVisibleIn($wedding)
            && true === $this->challenge($wedding)?->acceptsAt(
                DateTimeImmutable::createFromInterface($this->clock->now()),
            )
        );
    }

    /**
     * @throws PollRejected
     */
    public function vote(Guest $guest, PollQuestion $question, PollChoice $choice): void
    {
        $wedding = $guest->getWedding();
        if ($question->getWedding() !== $wedding || !$this->isOpenIn($wedding) || !$wedding->isActive()) {
            throw new PollRejected('Hier kann gerade nicht abgestimmt werden.');
        }

        $vote = $this->em->getRepository(PollVote::class)->findOneBy(['question' => $question, 'guest' => $guest]);
        if (null === $vote) {
            $this->em->persist(new PollVote($question, $guest, $choice));
        } else {
            $vote->change($choice);
        }

        $this->em->flush();
    }

    /**
     * @param list<PollQuestion> $questions
     *
     * @return array<string, PollChoice> eigene Stimme je Frage-ID
     */
    public function votesBy(Guest $guest, array $questions): array
    {
        $votes = [];
        foreach ($this->rows(
            'SELECT question_id, choice FROM poll_vote WHERE question_id IN (?) AND guest_id = ?',
            $questions,
            $guest,
        ) as [$id, $choice]) {
            $votes[$id] = $choice;
        }

        return $votes;
    }

    /**
     * Für die Aufgabenliste: eigene beantwortete Fragen und Fragen insgesamt.
     *
     * @return array{answered: int, total: int}
     */
    public function progressOf(Guest $guest): array
    {
        $questions = $this->questions->forWedding($guest->getWedding());

        return ['answered' => count($this->votesBy($guest, $questions)), 'total' => count($questions)];
    }

    /**
     * @param list<PollQuestion> $questions
     *
     * @return array<string, array{first: int, second: int}> Stimmen je Frage-ID
     */
    public function tally(array $questions): array
    {
        $counts = [];
        $sql = 'SELECT question_id, choice, COUNT(*) AS n FROM poll_vote WHERE question_id IN (?) GROUP BY question_id, choice';
        foreach ($this->rows($sql, $questions) as [$id, $choice, $count]) {
            $counts[$id][$choice->value] = $count;
        }

        $tally = [];
        foreach ($questions as $question) {
            $id = $question->getId()->toRfc4122();
            $tally[$id] = ['first' => $counts[$id]['first'] ?? 0, 'second' => $counts[$id]['second'] ?? 0];
        }

        return $tally;
    }

    /**
     * Liest Zeilen mit Frage-ID (binär), Wahl und optional Anzahl; ungültige Zeilen werden übersprungen.
     *
     * @param list<PollQuestion> $questions
     *
     * @return list<array{string, PollChoice, int}> [Frage-ID, Wahl, Anzahl]
     */
    private function rows(string $sql, array $questions, ?Guest $guest = null): array
    {
        if ([] === $questions) {
            return [];
        }

        $parameters = [self::ids($questions)];
        $types = [ArrayParameterType::BINARY];
        if (null !== $guest) {
            $parameters[] = $guest->getId()->toBinary();
            $types[] = ParameterType::BINARY;
        }

        return array_values(array_filter(array_map(
            self::parse(...),
            $this->em->getConnection()->fetchAllAssociative($sql, $parameters, $types),
        )));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{string, PollChoice, int}|null
     */
    private static function parse(array $row): ?array
    {
        $id = is_string($row['question_id'] ?? null) ? $row['question_id'] : null;
        $choice = PollChoice::tryFrom(is_string($row['choice'] ?? null) ? $row['choice'] : '');
        $count = is_numeric($row['n'] ?? null) ? (int) $row['n'] : 1;

        return null === $id || null === $choice ? null : [Uuid::fromBinary($id)->toRfc4122(), $choice, $count];
    }

    /**
     * @param list<PollQuestion> $questions
     *
     * @return list<string>
     */
    private static function ids(array $questions): array
    {
        return array_map(static fn(PollQuestion $q): string => $q->getId()->toBinary(), $questions);
    }
}
