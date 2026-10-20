<?php

declare(strict_types=1);

namespace App\Challenge;

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
            'last-drink' => [
                'title' => 'Last Drink',
                'description' =>
                    'Das Gegenstück zum First Drink: Gleiche Fragen, letztes Glas, späte Stunde. '
                        . 'Und dazu: Wie war dein Abend?',
                'type' => ChallengeMediaType::Video,
                'points' => 15,
            ],
        ];
    }
}
