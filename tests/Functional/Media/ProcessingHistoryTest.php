<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Media\Entity\Media;
use App\Media\Entity\VideoProcessingRecord;
use App\Media\MediaType;
use App\Media\Processing\ProcessingForecast;
use App\Media\Processing\ProcessVideo;
use App\Media\ProcessingStatus;
use App\Media\Repository\VideoProcessingRecordRepository;
use App\Media\Storage\MediaFile;
use App\Media\Storage\MediaStorage;
use App\Media\Visibility;
use App\Tests\Functional\WebTestCase;
use App\Tests\Integration\Media\VideoFixtures;
use App\Wedding\ModuleType;
use App\Wedding\WeddingEraser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;

final class ProcessingHistoryTest extends WebTestCase
{
    protected function tearDown(): void
    {
        $root = $_SERVER['MEDIA_ROOT'] ?? null;
        new Filesystem()->remove(is_string($root) ? $root : '/tmp/wedora-test-media');
        parent::tearDown();
    }

    public function testSuccessfulTranscodeIsRecordedAndSurvivesWeddingErasure(): void
    {
        $media = $this->queuedVideo(VideoFixtures::landscapeH264(), 4.0, 1920, 1080);
        $before = self::service(VideoProcessingRecordRepository::class)->count([]);

        self::service(MessageBusInterface::class)->dispatch(new ProcessVideo($media->getId()->toRfc4122()));

        $records = self::service(VideoProcessingRecordRepository::class)->findBy([], ['createdAt' => 'DESC'], 1);
        self::assertSame($before + 1, self::service(VideoProcessingRecordRepository::class)->count([]));
        $record = $records[0];
        self::assertTrue($record->isSucceeded());
        self::assertSame('h264', $record->getInputCodec());
        self::assertSame([1920, 1080], [$record->getInputWidth(), $record->getInputHeight()]);
        self::assertEqualsWithDelta(4.0, $record->getInputSeconds(), 0.5);
        self::assertGreaterThan(0, $record->getProcessingMs());
        self::assertGreaterThanOrEqual(0, $record->getQueueWaitMs());
        self::assertGreaterThan(0, (int) $record->getOutputBytes());
        self::assertSame(ProcessingStatus::Ready, self::fresh($media)->getProcessingStatus());

        self::service(WeddingEraser::class)->erase(self::fresh($media)->getWedding());
        self::assertSame(
            $before + 1,
            self::service(VideoProcessingRecordRepository::class)->count([]),
            'Anonyme Messwerte bleiben erhalten.',
        );
    }

    public function testRejectedVideoIsRecordedAsFailure(): void
    {
        $media = $this->queuedVideo(VideoFixtures::garbage(), 3.0, 1280, 720);

        self::service(MessageBusInterface::class)->dispatch(new ProcessVideo($media->getId()->toRfc4122()));

        $record = self::service(VideoProcessingRecordRepository::class)->findBy([], ['createdAt' => 'DESC'], 1)[0];
        self::assertFalse($record->isSucceeded());
        self::assertNotNull($record->getFailureReason());
        self::assertSame(ProcessingStatus::Failed, self::fresh($media)->getProcessingStatus());
    }

    public function testForecastIncludesVideosAheadInQueue(): void
    {
        $em = self::em();
        foreach ([10, 20, 30, 40, 50] as $seconds) {
            // 2 s Startaufwand + 1 s je Videosekunde.
            $em->persist(
                new VideoProcessingRecord($seconds, 1000, 1920, 1080, 'h264', 2000 + ($seconds * 1000), 0, true),
            );
        }
        $em->flush();

        $first = $this->queuedVideo(null, 30.0, 1920, 1080);
        $second = $this->queuedVideo(null, 30.0, 1920, 1080);
        $forecast = self::service(ProcessingForecast::class);

        $own = $forecast->remainingSeconds(self::fresh($first));
        $behind = $forecast->remainingSeconds(self::fresh($second));

        self::assertNotNull($own);
        self::assertNotNull($behind);
        self::assertEqualsWithDelta(32, $own, 2, 'eigene Umwandlung: 2 s + 30 s');
        self::assertEqualsWithDelta(64, $behind, 3, 'wartet zusätzlich auf das Video davor');
    }

    public function testNoForecastForPhotosOrFinishedVideos(): void
    {
        $em = self::em();
        foreach ([10, 20, 30, 40, 50] as $seconds) {
            $em->persist(new VideoProcessingRecord($seconds, 1000, 1920, 1080, 'h264', $seconds * 1000, 0, true));
        }
        $em->flush();
        [$guest] = $this->fixtures->guest($this->fixtures->wedding());
        $photo = $this->fixtures->guestMedia($guest);
        $readyVideo = $this->fixtures->guestMedia($guest, type: MediaType::Video);

        $forecast = self::service(ProcessingForecast::class);
        self::assertNull($forecast->remainingSeconds($photo));
        self::assertNull($forecast->remainingSeconds($readyVideo));
    }

    private function queuedVideo(?string $file, float $seconds, int $width, int $height): Media
    {
        [$guest] = $this->fixtures->guest($this->fixtures->wedding());
        $media = Media::uploadedByGuest(
            self::fresh($guest),
            ModuleType::Gallery,
            MediaType::Video,
            Visibility::AllGuests,
        );
        $media->recordOriginal('video/mp4', null === $file ? 1000 : (int) filesize($file), $width, $height, $seconds);
        self::em()->persist($media);
        self::em()->flush();

        if (null !== $file) {
            self::service(MediaStorage::class)->store($media->mediaId(), MediaFile::Original, $file);
        }

        return $media;
    }
}
