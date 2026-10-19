<?php

declare(strict_types=1);

namespace App\Tests\Unit\Gallery;

use App\Gallery\MagazineLayout;
use App\Media\Entity\Media;
use App\Media\MediaType;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class MagazineLayoutTest extends TestCase
{
    public function testMostLikedItemsTakeTheLargeSlotsOfEachBlock(): void
    {
        $items = self::media(12);
        $likes = [
            self::id($items[3]) => 9, // Block 1: Highlight → Position 1
            self::id($items[4]) => 5, // Block 1: zweitbestes → Position 6
            self::id($items[10]) => 3, // Block 2: Highlight
        ];

        $arranged = MagazineLayout::arrange($items, $likes);

        self::assertCount(12, $arranged);
        self::assertSame($items[3], $arranged[0]);
        self::assertSame($items[4], $arranged[5]);
        self::assertSame(
            [$items[0], $items[1], $items[2], $items[5]],
            array_slice($arranged, 1, 4),
            'übrige in zeitlicher Reihenfolge',
        );
        self::assertSame($items[10], $arranged[6]);
        self::assertEqualsCanonicalizing(
            array_map(self::id(...), $items),
            array_map(self::id(...), $arranged),
            'nichts geht verloren',
        );
    }

    public function testPhotosWinTiesOverVideos(): void
    {
        $fixtures = new Fixtures();
        [$guest] = $fixtures->guest($fixtures->wedding());
        $items = [
            $fixtures->guestMedia($guest, type: MediaType::Video),
            $fixtures->guestMedia($guest, type: MediaType::Video),
            $fixtures->guestMedia($guest),
            $fixtures->guestMedia($guest, type: MediaType::Video),
        ];

        $arranged = MagazineLayout::arrange($items, []);

        self::assertSame($items[2], $arranged[0], 'ohne Likes: ein Foto für den großen Platz');
        self::assertCount(4, $arranged);
    }

    public function testVerySmallBlocksStayUntouched(): void
    {
        $items = self::media(2);

        self::assertSame($items, MagazineLayout::arrange($items, [self::id($items[1]) => 5]));
    }

    /**
     * @return list<Media>
     */
    private static function media(int $count): array
    {
        $fixtures = new Fixtures();
        [$guest] = $fixtures->guest($fixtures->wedding());

        return array_map(static fn(): Media => $fixtures->guestMedia($guest), range(1, $count));
    }

    private static function id(Media $media): string
    {
        return $media->getId()->toRfc4122();
    }
}
