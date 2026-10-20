<?php

declare(strict_types=1);

namespace App\Challenge;

/**
 * Wählt die Linien-Illustration einer Aufgabe anhand ihres Titels (Vorschläge und frei benannte Aufgaben).
 */
final class ChallengeMotif
{
    public const string DEFAULT = 'camera';

    /** @var array<string, list<string>> Motiv => Stichwörter (klein geschrieben) */
    private const array KEYWORDS = [
        'moon' => ['last drink', 'letzte', 'absacker', 'mitternacht'],
        // Vor „glasses“, damit „Ein Drink für Wolfgang“ nicht als First Drink gilt.
        'bottle' => ['drink für', 'drink aus', 'spendier', 'ausgeben'],
        'glasses' => ['drink', 'anstoßen', 'sekt', 'champagner', 'prost', 'toast', 'glas'],
        'balloons' => ['wer von beiden', 'umfrage'],
        'pencil' => ['gezeichnet', 'porträt'],
        'couple' => ['first dance', 'erster tanz', 'hochzeitstanz', 'brautpaar tanzt'],
        'dance' => ['dancefloor', 'tanz', 'dance', 'party', 'disco'],
        'hands' => ['friend', 'freund', 'kennengelernt', 'selfie', 'gruppe'],
        'envelope' => ['nachricht', 'gruß', 'message', 'brief', 'wünsch'],
        'rings' => ['ring', 'ja-wort', 'trauung', 'kuss'],
        'cake' => ['torte', 'kuchen', 'cake', 'dessert'],
        'bouquet' => ['strauß', 'blume', 'bouquet', 'brautstrauß'],
    ];

    public static function for(string $title): string
    {
        $title = mb_strtolower($title);
        foreach (self::KEYWORDS as $motif => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($title, $keyword)) {
                    return $motif;
                }
            }
        }

        return self::DEFAULT;
    }
}
