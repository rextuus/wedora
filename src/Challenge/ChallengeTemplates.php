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
                'description' => 'Stoße an und halte dein erstes Glas des Abends fest!',
                'type' => ChallengeMediaType::Photo,
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
            'last-drink' => [
                'title' => 'Last Drink',
                'description' => 'Dein letztes Glas zum Abschluss des Abends.',
                'type' => ChallengeMediaType::Photo,
                'points' => 10,
            ],
        ];
    }
}
