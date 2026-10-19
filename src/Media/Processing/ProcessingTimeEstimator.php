<?php

declare(strict_types=1);

namespace App\Media\Processing;

use App\Media\Entity\VideoProcessingRecord;

/**
 * Schätzt die Umwandlungsdauer aus bisherigen Messungen.
 *
 * Modell: Dauer = Startaufwand + Faktor × Videolänge (Ausgleichsgerade), getrennt nach
 * Auflösungsklasse. Reichen die Daten einer Klasse nicht, werden alle Klassen genutzt;
 * ohne genug Daten gibt es keine Schätzung (die Oberfläche zeigt dann einen allgemeinen Hinweis).
 */
final class ProcessingTimeEstimator
{
    public const int MIN_SAMPLES = 5;

    /**
     * @param list<VideoProcessingRecord> $records
     */
    public static function estimateSeconds(array $records, float $durationSeconds, int $width, int $height): ?float
    {
        $class = self::resolutionClass($width, $height);
        $sameClass = array_values(array_filter(
            $records,
            static fn(VideoProcessingRecord $r): bool => (
                self::resolutionClass($r->getInputWidth(), $r->getInputHeight()) === $class
            ),
        ));
        $samples = count($sameClass) >= self::MIN_SAMPLES ? $sameClass : $records;

        if (count($samples) < self::MIN_SAMPLES) {
            return null;
        }

        [$fixed, $perSecond] = self::fit($samples);

        return max(1.0, $fixed + ($perSecond * max(0.0, $durationSeconds)));
    }

    /**
     * 'hd' bis 1280 px lange Seite, 'fullhd' bis 1920 px, darüber 'uhd'.
     */
    public static function resolutionClass(int $width, int $height): string
    {
        $longEdge = max($width, $height);

        return match (true) {
            $longEdge <= 1280 => 'hd',
            $longEdge <= 1920 => 'fullhd',
            default => 'uhd',
        };
    }

    /**
     * Kleinste-Quadrate-Gerade über (Videolänge, Umwandlungszeit). Liegen alle Längen
     * zu dicht beieinander, wird der mittlere Faktor (Median) ohne Startaufwand genutzt.
     *
     * @param list<VideoProcessingRecord> $samples
     *
     * @return array{float, float} [Startaufwand in s, Sekunden je Videosekunde]
     */
    public static function fit(array $samples): array
    {
        $n = count($samples);
        $xs = array_map(static fn(VideoProcessingRecord $r): float => $r->getInputSeconds(), $samples);
        $ys = array_map(static fn(VideoProcessingRecord $r): float => $r->getProcessingMs() / 1000, $samples);
        $meanX = array_sum($xs) / $n;
        $meanY = array_sum($ys) / $n;

        $sxx = 0.0;
        $sxy = 0.0;
        foreach ($xs as $i => $x) {
            $sxx += ($x - $meanX) ** 2;
            $sxy += ($x - $meanX) * ($ys[$i] - $meanY);
        }

        if ($sxx < 1.0) {
            $ratios = array_map(static fn(float $x, float $y): float => $x > 0 ? $y / $x : 0.0, $xs, $ys);

            return [0.0, self::median($ratios)];
        }

        $perSecond = max(0.0, $sxy / $sxx);

        return [max(0.0, $meanY - ($perSecond * $meanX)), $perSecond];
    }

    /**
     * @param list<float> $values
     */
    public static function median(array $values): float
    {
        if ([] === $values) {
            return 0.0;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return 0 === (count($values) % 2) ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle];
    }
}
