<?php

declare(strict_types=1);

namespace App\Gallery;

use App\Media\Entity\Media;
use App\Media\MediaType;

/**
 * Ordnet Beiträge für die Magazin-Galerie: Blöcke zu je sechs, im Wechsel
 * „groß links + zwei klein rechts“ und „zwei klein links + groß rechts“.
 *
 * Die beiden großen Plätze eines Blocks (Position 1 und 6) erhalten dessen Highlights:
 * die meisten Likes, bei Gleichstand Fotos vor Videos, sonst das neuere. Innerhalb des
 * Blocks bleibt die zeitliche Reihenfolge der übrigen Beiträge erhalten.
 */
final class MagazineLayout
{
    public const int BLOCK = 6;

    /**
     * @param list<Media>        $items      neueste zuerst
     * @param array<string, int> $likeCounts Likes je Medien-ID
     *
     * @return list<Media>
     */
    public static function arrange(array $items, array $likeCounts): array
    {
        $arranged = [];
        foreach (array_chunk($items, self::BLOCK) as $block) {
            $arranged = [...$arranged, ...self::arrangeBlock($block, $likeCounts)];
        }

        return $arranged;
    }

    /**
     * @param list<Media>        $block
     * @param array<string, int> $likeCounts
     *
     * @return list<Media>
     */
    private static function arrangeBlock(array $block, array $likeCounts): array
    {
        if (count($block) < 3) {
            return $block;
        }

        $ranked = $block;
        usort(
            $ranked,
            static fn(Media $a, Media $b): int => self::score($b, $likeCounts) <=> self::score($a, $likeCounts),
        );
        $first = $ranked[0];
        $second = count($block) === self::BLOCK ? $ranked[1] : null;

        $rest = array_values(array_filter($block, static fn(Media $m): bool => $m !== $first && $m !== $second));

        return null === $second ? [$first, ...$rest] : [$first, ...$rest, $second];
    }

    /**
     * @param array<string, int> $likeCounts
     *
     * @return array{int, int, string}
     */
    private static function score(Media $media, array $likeCounts): array
    {
        return [
            $likeCounts[$media->getId()->toRfc4122()] ?? 0,
            MediaType::Photo === $media->getType() ? 1 : 0,
            $media->getId()->toRfc4122(),
        ];
    }
}
