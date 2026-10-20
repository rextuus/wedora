<?php

declare(strict_types=1);

namespace App\Challenge;

use App\Wedding\Entity\Wedding;
use App\Wedding\Host\HostPronoun;

/**
 * Vorschläge aus dem Konzept; die Hochzeitsadministration kann sie übernehmen und anpassen.
 */
final class ChallengeTemplates
{
    /**
     * @return array<string, array{title: string, description: string, type: ChallengeMediaType, points: int}>
     */
    public static function all(): array
    {
        return [
            'first-drink' => [
                'title' => 'First Drink',
                'description' =>
                    'Dreh gleich zu Beginn ein kurzes Video mit deinem ersten Glas: Wer bist du, was trinkst du, '
                        . 'was wünschst du dem Brautpaar? Damit bist du offiziell dabei – das Gegenstück folgt am Ende des Abends.',
                'type' => ChallengeMediaType::Video,
                'points' => 10,
            ],
            'first-dance' => [
                'title' => 'First Dance',
                'description' => 'Fang den ersten Tanz des Brautpaares ein.',
                'type' => ChallengeMediaType::Any,
                'points' => 10,
            ],
            'new-friends' => [
                'title' => 'New Friends',
                'description' => 'Ein Foto mit jemandem, den du heute kennengelernt hast.',
                'type' => ChallengeMediaType::Photo,
                'points' => 15,
            ],
            'dancefloor' => [
                'title' => 'Dancefloor Madness',
                'description' => 'Zeig uns die Stimmung auf der Tanzfläche – gerne als kurzes Video.',
                'type' => ChallengeMediaType::Video,
                'points' => 15,
            ],
            'message' => [
                'title' => 'Nachricht an das Brautpaar',
                'description' => 'Ein kurzer Video-Gruß für das Brautpaar.',
                'type' => ChallengeMediaType::Video,
                'points' => 20,
            ],
            'poll' => [
                'title' => 'Wer von beiden?',
                'description' => 'Kleine Umfrage: Was meinst du, wer von den beiden macht was mehr?',
                'type' => ChallengeMediaType::Poll,
                'points' => 10,
            ],
            'portrait-buddy' => [
                'title' => 'Foto mit einem gezeichneten Gast',
                'description' => 'Die App lost dir jemanden von der Gästewand zu – findet euch und macht ein gemeinsames Foto.',
                'type' => ChallengeMediaType::Buddy,
                'points' => 15,
            ],
            'last-drink' => [
                'title' => 'Last Drink',
                'description' =>
                    'Das Gegenstück zum First Drink: Gleiche Fragen, letztes Glas, späte Stunde. '
                        . 'Und dazu: Wie war dein Abend?',
                'type' => ChallengeMediaType::Video,
                'points' => 15,
            ],
            // Scherzaufgabe; Name und „seine/ihre“ kommen aus dem Gastgeber in den Einstellungen.
            'host-drink' => [
                'title' => 'Ein Drink für {gastgeber}',
                'description' => 'Gib {gastgeber} einen Drink aus – für {seine} „super“ Arbeit an dieser App. Beweisfoto mit Drink!',
                'type' => ChallengeMediaType::Photo,
                'points' => 5,
            ],
        ];
    }

    /**
     * Vorschläge für eine Hochzeit: Platzhalter ersetzt; die Gastgeber-Aufgabe nur, wenn ein Gastgeber eingetragen ist.
     *
     * @return array<string, array{title: string, description: string, type: ChallengeMediaType, points: int}>
     */
    public static function forWedding(Wedding $wedding): array
    {
        $host = $wedding->getPresentation()->getHostName();
        $templates = self::all();
        if (null === $host) {
            unset($templates['host-drink']);

            return $templates;
        }

        $replace = [
            '{gastgeber}' => $host,
            '{seine}' => match ($wedding->getPresentation()->getHostPronoun()) {
                HostPronoun::He => 'seine',
                HostPronoun::She => 'ihre',
                null => 'die',
            },
        ];

        return array_map(static fn(array $t): array => [
            ...$t,
            'title' => strtr($t['title'], $replace),
            'description' => strtr($t['description'], $replace),
        ], $templates);
    }
}
