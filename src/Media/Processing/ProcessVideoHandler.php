<?php

declare(strict_types=1);

namespace App\Media\Processing;

use App\Media\Entity\Media;
use App\Media\Entity\VideoProcessingRecord;
use App\Media\ProcessingStatus;
use App\Media\Repository\MediaRepository;
use App\Media\Storage\MediaFile;
use App\Media\Storage\MediaStorage;
use App\Media\Video\VideoProbe;
use App\Media\Video\VideoRejected;
use App\Media\Video\VideoTranscoder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Wandelt ein Video im Worker um und protokolliert die Messwerte (auch bei Fehlern)
 * für die Zeitprognose. Fachliche Ablehnung → sofort „fehlgeschlagen“; technische
 * Fehler → Messenger wiederholt, danach markiert ProcessingFailureListener.
 */
#[AsMessageHandler]
final readonly class ProcessVideoHandler
{
    public function __construct(
        private MediaRepository $media,
        private MediaStorage $storage,
        private VideoTranscoder $transcoder,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private MessageBusInterface $bus,
        #[Autowire('%env(int:VIDEO_FFMPEG_THREADS)%')]
        private int $threads,
    ) {}

    public function __invoke(ProcessVideo $message): void
    {
        $media = Uuid::isValid($message->mediaId) ? $this->media->find(Uuid::fromString($message->mediaId)) : null;
        if (null === $media || $media->isDeleted() || ProcessingStatus::Processing !== $media->getProcessingStatus()) {
            return;
        }

        $id = $media->mediaId();
        $queueWaitMs = $this->millisecondsSince($media->getCreatedAt());
        $started = microtime(true);

        try {
            $probe = $this->transcoder->transcode(
                $this->storage->localPath($id, MediaFile::Original),
                $this->storage->localPath($id, MediaFile::Video),
                $this->storage->localPath($id, MediaFile::Poster),
            );
        } catch (VideoRejected $e) {
            $this->logger->info('Video abgelehnt.', ['media' => $id->value, 'reason' => $e->getMessage()]);
            $this->record($media, $started, $queueWaitMs, null, $e->getMessage(), null);
            $media->markFailed('Das Video konnte nicht verarbeitet werden.');
            $this->em->flush();

            return;
        } catch (Throwable $e) {
            // Messwert festhalten, dann an Messenger zur Wiederholung weiterreichen.
            $this->record($media, $started, $queueWaitMs, null, $e->getMessage(), null);
            $this->em->flush();

            throw $e;
        }

        $derived = $this->storage->size($id, MediaFile::Video) + $this->storage->size($id, MediaFile::Poster);
        $this->record($media, $started, $queueWaitMs, $derived, null, $probe->videoCodec);
        $this->ready($media, $derived, $probe);
    }

    /** Fertig; die Vorschaubilder aus dem Standbild kommen per eigenem Auftrag (ein Fehler dort lässt das Video fertig). */
    private function ready(Media $media, int $derived, VideoProbe $probe): void
    {
        $media->markReady($derived, $probe->width, $probe->height, $probe->durationSeconds);
        $this->em->flush();
        $this->bus->dispatch(new GenerateImageVariants($media->getId()->toRfc4122()));
    }

    // Flache Messwerte; eine Hilfsstruktur würde die Lesbarkeit hier nicht verbessern.
    // @mago-expect lint:excessive-parameter-list
    private function record(
        Media $media,
        float $started,
        int $queueWaitMs,
        ?int $outputBytes,
        ?string $failure,
        ?string $codec,
    ): void {
        $this->em->persist(new VideoProcessingRecord(
            inputSeconds: $media->getDurationSeconds() ?? 0.0,
            inputBytes: $media->getOriginalBytes(),
            inputWidth: $media->getWidth() ?? 0,
            inputHeight: $media->getHeight() ?? 0,
            inputCodec: mb_substr($codec ?? $media->getOriginalMimeType(), 0, 32),
            processingMs: (int) round((microtime(true) - $started) * 1000),
            queueWaitMs: $queueWaitMs,
            succeeded: null === $failure,
            outputBytes: $outputBytes,
            failureReason: $failure,
            threads: $this->threads,
        ));
    }

    /**
     * Sekundengenau: created_at wird in der Datenbank ohne Bruchteile gespeichert.
     */
    private function millisecondsSince(DateTimeImmutable $since): int
    {
        $now = DateTimeImmutable::createFromInterface($this->clock->now());

        return max(0, ($now->getTimestamp() - $since->getTimestamp()) * 1000);
    }
}
