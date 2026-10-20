<?php

declare(strict_types=1);

namespace App\Tests\Unit\Wedding;

use App\Poll\PollChoice;
use App\Wedding\CoupleNames;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoupleNamesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function titles(): iterable
    {
        yield 'Kaufmanns-Und' => ['Larissa & Tim', 'Larissa', 'Tim'];
        yield 'und' => ['Mia und Ben', 'Mia', 'Ben'];
        yield 'Plus, Leerzeichen' => ['  Kim + Alex ', 'Kim', 'Alex'];
        yield 'Doppelname bleibt zusammen' => ['Anna-Lena & Jan Peter', 'Anna-Lena', 'Jan Peter'];
        yield 'kein Paar im Titel' => ['Sommerfest 2027', 'Person 1', 'Person 2'];
        yield '„und“ im Namen zählt nicht' => ['Undine & Jo', 'Undine', 'Jo'];
    }

    #[DataProvider('titles')]
    public function testDerivesBothNamesFromTheTitle(string $title, string $first, string $second): void
    {
        $names = CoupleNames::fromTitle($title);

        self::assertSame($first, $names->name(PollChoice::First));
        self::assertSame($second, $names->name(PollChoice::Second));
    }
}
