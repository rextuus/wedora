<?php

declare(strict_types=1);

namespace App\Tests\Unit\Challenge;

use App\Challenge\ChallengeMotif;
use App\Challenge\ChallengeTemplates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChallengeMotifTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function titles(): iterable
    {
        yield 'Vorschlag First Drink' => ['First Drink', 'glasses'];
        yield 'Vorschlag Last Drink geht vor Drink' => ['Last Drink', 'moon'];
        yield 'Vorschlag First Dance' => ['First Dance', 'couple'];
        yield 'Vorschlag Tanzfläche' => ['Dancefloor Madness', 'dance'];
        yield 'Vorschlag Nachricht' => ['Nachricht an das Brautpaar', 'envelope'];
        yield 'freie Aufgabe, Groß-/Kleinschreibung egal' => ['Foto mit der TORTE', 'cake'];
        yield 'unbekannt' => ['Lustigste Grimasse', ChallengeMotif::DEFAULT];
    }

    #[DataProvider('titles')]
    public function testPicksMotifFromTitle(string $title, string $expected): void
    {
        self::assertSame($expected, ChallengeMotif::for($title));
    }

    public function testEverySuggestionHasItsOwnMotif(): void
    {
        $motifs = array_map(static fn(array $t): string => ChallengeMotif::for($t['title']), ChallengeTemplates::all());

        self::assertNotContains(ChallengeMotif::DEFAULT, $motifs);
        self::assertCount(count($motifs), array_unique($motifs));
    }
}
