<?php

declare(strict_types=1);

namespace App\Media\Upload;

use App\Challenge\Entity\Challenge;
use App\Guest\Entity\Guest;
use App\Media\Entity\Media;
use App\Media\Entity\Upload;
use App\Media\MediaType;
use App\Media\Processing\ProcessVideo;
use App\Media\Storage\MediaFile;
use App\Media\Storage\MediaStorage;
use App\Media\Video\VideoProbe;
use App\Media\Video\VideoRejected;
use App\Media\Video\VideoTranscoder;
use App\Wedding\ModuleType;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use finfo;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Upload in Stücken mit serverseitigen Limits (ADR 0003):
 * Dateigröße je Typ, Kontingent pro Hochzeit und Gast (inkl. Reservierungen),
 * erlaubte Formate (am Inhalt erkannt), Videolänge und gleichzeitige Uploads pro Gast.
 */
final readonly class UploadService
{
    public const int CHUNK_BYTES = 8 * 1024 * 1024;
    public const int MAX_OPEN_UPLOADS_PER_GUEST = 3;

    /** Erlaubte Formate, am Dateiinhalt erkannt (nicht an Endung oder Browserangabe). */
    private const array ALLOWED = [
        'photo' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'],
        'video' => ['video/mp4', 'video/quicktime', 'video/webm', 'video/3gpp'],
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private UploadBuffer $buffer,
        private StorageUsage $usage,
        private MediaStorage $storage,
        private VideoTranscoder $transcoder,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        #[Autowire('%env(int:VIDEO_MAX_DURATION_SECONDS)%')]
        private int $platformMaxVideoSeconds,
    ) {}

    public function start(Guest $guest, UploadRequest $request, ?Challenge $challenge = null): Upload
    {
        $type = $request->type;
        $bytes = $request->bytes;
        $wedding = $guest->getWedding();
        $limits = $wedding->limits();
        $now = $this->now();
        $maxBytes = MediaType::Video === $type ? $limits->maxVideoBytes() : $limits->maxPhotoBytes();

        if ($bytes < 1) {
            throw new UploadRejected('Die Datei ist leer.');
        }

        if ($bytes > $maxBytes) {
            throw new UploadRejected(
                sprintf('Die Datei ist zu groß (höchstens %s).', self::humanBytes($maxBytes)),
                413,
            );
        }

        if ($this->openUploads($guest, $now) >= self::MAX_OPEN_UPLOADS_PER_GUEST) {
            throw new UploadRejected('Bitte warte, bis deine laufenden Uploads fertig sind.', 429);
        }

        if (($this->usage->forWedding($wedding, $now) + $bytes) > $limits->storageQuotaBytes()) {
            throw new UploadRejected('Der Speicher dieser Hochzeit ist voll. Bitte sprich das Brautpaar an.', 507);
        }

        $guestQuota = $limits->guestQuotaBytes();
        if (null !== $guestQuota && ($this->usage->forGuest($guest, $now) + $bytes) > $guestQuota) {
            throw new UploadRejected(
                sprintf('Du hast dein Kontingent von %s erreicht.', self::humanBytes($guestQuota)),
                507,
            );
        }

        $module = $request->module;
        if (null !== $challenge) {
            $this->assertChallengeOpen($guest, $challenge, $type, $now);
            $module = ModuleType::Challenges;
        }

        $upload = new Upload($guest, $module, $type, $request->visibility, $bytes, $now);
        $upload->forChallenge($challenge);
        $upload->withCaption($request->caption);
        $this->em->persist($upload);
        $this->em->flush();

        return $upload;
    }

    /**
     * Hängt ein Stück an. Ist der Upload danach vollständig, wird er abgeschlossen
     * und das entstandene Medium zurückgegeben.
     *
     * @param resource $stream
     */
    public function append(Upload $upload, int $offset, $stream): ?Media
    {
        if ($upload->isFinished()) {
            return $upload->getMedia();
        }

        if ($upload->isExpiredAt($this->now())) {
            throw new UploadRejected('Dieser Upload ist abgelaufen. Bitte starte ihn neu.', 410);
        }

        if ($offset !== $upload->getReceivedBytes()) {
            throw new UploadOffsetMismatch($upload->getReceivedBytes());
        }

        $remaining = $upload->getDeclaredBytes() - $upload->getReceivedBytes();
        $written = $this->buffer->append($upload, $stream, min(self::CHUNK_BYTES, $remaining));
        $upload->received($written);
        $this->em->flush();

        return $upload->isComplete() ? $this->finish($upload) : null;
    }

    public function abort(Upload $upload): void
    {
        $this->buffer->delete($upload);
        if (!$upload->isFinished()) {
            $this->em->remove($upload);
            $this->em->flush();
        }
    }

    private function finish(Upload $upload): Media
    {
        $path = $this->buffer->path($upload);
        $mime = (string) new finfo(FILEINFO_MIME_TYPE)->file($path);
        $type = $upload->getType();

        if (!in_array($mime, self::ALLOWED[$type->value], true)) {
            $this->reject(
                $upload,
                MediaType::Photo === $type
                    ? 'Dieses Bildformat wird nicht unterstützt (JPEG, PNG, WebP, HEIC).'
                    : 'Dieses Videoformat wird nicht unterstützt (MP4, MOV, WebM).',
            );
        }

        $media = Media::uploadedByGuest($upload->getGuest(), $upload->getModule(), $type, $upload->getVisibility());
        $media->changeCaption($upload->getCaption());
        $media->assignChallenge($upload->getChallenge());

        if (MediaType::Photo === $type) {
            // HEIC kann PHP nicht vermessen; die Abmessungen bleiben dann leer.
            $size = in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) ? getimagesize($path) : false;
            $media->recordOriginal(
                $mime,
                $upload->getDeclaredBytes(),
                $size ? $size[0] : null,
                $size ? $size[1] : null,
            );
            $media->markReady();
        } else {
            $probe = $this->probeVideo($upload, $path);
            $media->recordOriginal(
                $mime,
                $upload->getDeclaredBytes(),
                $probe->width,
                $probe->height,
                $probe->durationSeconds,
            );
        }

        $this->storage->adopt($media->mediaId(), MediaFile::Original, $path);
        $upload->complete($media);
        $this->em->persist($media);
        $this->em->flush();

        if (MediaType::Video === $type) {
            $this->bus->dispatch(new ProcessVideo($media->getId()->toRfc4122()));
        }

        return $media;
    }

    /**
     * Länge sofort prüfen, damit der Gast direkt Rückmeldung bekommt.
     */
    private function probeVideo(Upload $upload, string $path): VideoProbe
    {
        $maxSeconds = min($upload->getWedding()->limits()->maxVideoSeconds(), $this->platformMaxVideoSeconds);

        try {
            $probe = $this->transcoder->probe($path);
        } catch (VideoRejected) {
            $this->reject($upload, 'Das Video konnte nicht gelesen werden.');
        }

        if ($probe->durationSeconds > $maxSeconds) {
            $this->reject($upload, sprintf('Das Video ist zu lang (höchstens %d Sekunden).', $maxSeconds));
        }

        return $probe;
    }

    private function assertChallengeOpen(
        Guest $guest,
        Challenge $challenge,
        MediaType $type,
        DateTimeImmutable $now,
    ): void {
        if ($challenge->getWedding() !== $guest->getWedding() || !$challenge->acceptsAt($now)) {
            throw new UploadRejected('Diese Aufgabe ist gerade nicht aktiv.', 403);
        }

        if (!$challenge->getMediaType()->accepts($type)) {
            throw new UploadRejected(sprintf(
                'Für diese Aufgabe wird ein %s erwartet.',
                $challenge->getMediaType()->label(),
            ));
        }
    }

    private function reject(Upload $upload, string $message): never
    {
        $this->abort($upload);

        throw new UploadRejected($message);
    }

    private function openUploads(Guest $guest, DateTimeImmutable $now): int
    {
        return (int) $this->em
            ->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(Upload::class, 'u')
            ->where('u.guest = :guest')
            ->andWhere('u.media IS NULL')
            ->andWhere('u.expiresAt > :now')
            ->setParameter('guest', $guest->getId(), UuidType::NAME)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }

    public static function humanBytes(int $bytes): string
    {
        return $bytes >= (1024 ** 3)
            ? sprintf('%s GB', rtrim(rtrim(number_format($bytes / (1024 ** 3), 1, ',', ''), '0'), ','))
            : sprintf('%d MB', (int) round($bytes / (1024 ** 2)));
    }
}
