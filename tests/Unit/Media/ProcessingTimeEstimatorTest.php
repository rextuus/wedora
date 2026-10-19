<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media;

use App\Media\Entity\VideoProcessingRecord;
use App\Media\Processing\ProcessingTimeEstimator;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class ProcessingTimeEstimatorTest extends TestCase
{
    public function testLinearFitRecoversStartupCostAndRate(): void
    {
        // Wahre Dauer: 3 s Startaufwand + 0,5 s je Videosekunde.
        $records = array_map(static fn(int $s): VideoProcessingRecord => self::record($s, 3000 + ($s * 500)), [
            10,
            20,
            30,
            60,
            90,
            120,
        ]);

        [$fixed, $perSecond] = ProcessingTimeEstimator::fit($records);

        self::assertEqualsWithDelta(3.0, $fixed, 0.01);
        self::assertEqualsWithDelta(0.5, $perSecond, 0.001);
        self::assertEqualsWithDelta(18.0, ProcessingTimeEstimator::estimateSeconds($records, 30, 1920, 1080), 0.01);
    }

    public function testNoEstimateWithTooFewSamples(): void
    {
        $records = array_map(static fn(int $s): VideoProcessingRecord => self::record($s, $s * 1000), [10, 20, 30, 40]);

        self::assertNull(ProcessingTimeEstimator::estimateSeconds($records, 30, 1920, 1080));
    }

    public function testUsesSameResolutionClassWhenEnoughData(): void
    {
        $fullHd = array_map(static fn(int $s): VideoProcessingRecord => self::record($s, $s * 1000, 1920, 1080), [
            10,
            20,
            30,
            40,
            50,
        ]);
        $uhd = array_map(static fn(int $s): VideoProcessingRecord => self::record($s, $s * 4000, 3840, 2160), [
            10,
            20,
            30,
            40,
            50,
        ]);
        $all = [...$fullHd, ...$uhd];

        self::assertEqualsWithDelta(
            30.0,
            ProcessingTimeEstimator::estimateSeconds($all, 30, 1080, 1920),
            0.01,
            'Hochformat Full HD',
        );
        self::assertEqualsWithDelta(120.0, ProcessingTimeEstimator::estimateSeconds($all, 30, 3840, 2160), 0.01, '4K');
    }

    public function testFallsBackToAllClassesWhenClassHasTooFewSamples(): void
    {
        $fullHd = array_map(static fn(int $s): VideoProcessingRecord => self::record($s, $s * 1000, 1920, 1080), [
            10,
            20,
            30,
            40,
            50,
        ]);
        $oneUhd = [self::record(30, 200_000, 3840, 2160)];

        $estimate = ProcessingTimeEstimator::estimateSeconds([...$fullHd, ...$oneUhd], 30, 3840, 2160);

        self::assertNotNull($estimate, 'Eine 4K-Messung reicht nicht – dann alle Klassen gemeinsam.');
    }

    public function testIdenticalDurationsUseMedianRate(): void
    {
        $records = array_map(static fn(int $ms): VideoProcessingRecord => self::record(10, $ms), [
            8000,
            9000,
            10_000,
            11_000,
            50_000,
        ]);

        [$fixed, $perSecond] = ProcessingTimeEstimator::fit($records);

        self::assertSame(0.0, $fixed);
        self::assertEqualsWithDelta(1.0, $perSecond, 0.001, 'Ausreißer (50 s) verzerrt den Median nicht.');
    }

    public function testEstimateIsNeverBelowOneSecond(): void
    {
        $records = array_map(static fn(int $s): VideoProcessingRecord => self::record($s, 10), [10, 20, 30, 40, 50]);

        self::assertSame(1.0, ProcessingTimeEstimator::estimateSeconds($records, 1, 640, 360));
    }

    #[TestWith([1280, 720, 'hd'])]
    #[TestWith([720, 1280, 'hd'])]
    #[TestWith([1920, 1080, 'fullhd'])]
    #[TestWith([1080, 1920, 'fullhd'])]
    #[TestWith([3840, 2160, 'uhd'])]
    #[TestWith([0, 0, 'hd'])]
    public function testResolutionClasses(int $width, int $height, string $expected): void
    {
        self::assertSame($expected, ProcessingTimeEstimator::resolutionClass($width, $height));
    }

    public function testMedian(): void
    {
        self::assertSame(0.0, ProcessingTimeEstimator::median([]));
        self::assertSame(2.0, ProcessingTimeEstimator::median([3.0, 1.0, 2.0]));
        self::assertSame(2.5, ProcessingTimeEstimator::median([4.0, 1.0, 2.0, 3.0]));
    }

    private static function record(
        float $seconds,
        int $processingMs,
        int $width = 1920,
        int $height = 1080,
    ): VideoProcessingRecord {
        return new VideoProcessingRecord($seconds, 1000, $width, $height, 'h264', $processingMs, 0, true);
    }
}
