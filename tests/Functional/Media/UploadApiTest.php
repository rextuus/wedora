<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Guest\Entity\Guest;
use App\Media\Entity\Media;
use App\Media\Entity\Upload;
use App\Media\Repository\MediaRepository;
use App\Media\Repository\UploadRepository;
use App\Media\Storage\MediaFile;
use App\Media\Storage\MediaStorage;
use App\Media\Upload\UploadBuffer;
use App\Tests\Functional\WebTestCase;
use App\Tests\Integration\Media\VideoFixtures;
use App\Wedding\ModerationMode;
use App\Wedding\ModuleType;
use DateTimeImmutable;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

// Das Upload-Protokoll hat viele Randfälle; jeder ist ein eigener, benannter Test.
// @mago-expect lint:too-many-methods
final class UploadApiTest extends WebTestCase
{
    private const string PHOTO = __DIR__ . '/../../Fixtures/phase0.jpg';

    protected function tearDown(): void
    {
        $root = $_SERVER['MEDIA_ROOT'] ?? null;
        new Filesystem()->remove(is_string($root) ? $root : '/tmp/wedora-test-media');
        parent::tearDown();
    }

    public function testPhotoUploadInChunksWithResume(): void
    {
        [$guest, $secret] = $this->fixtures->guest($this->fixtures->wedding());
        $this->actAsGuest($guest, $secret);
        $content = (string) file_get_contents(self::PHOTO);

        $id = $this->start($guest, 'photo', strlen($content));

        $this->patch($guest, $id, 0, substr($content, 0, 1000));
        self::assertResponseStatusCodeSame(204);
        self::assertResponseHeaderSame('Upload-Offset', '1000');

        // Fortsetzen nach Abbruch: Stand erfragen.
        $this->client->request('HEAD', $this->uploadUrl($guest, $id));
        self::assertResponseHeaderSame('Upload-Offset', '1000');
        self::assertResponseHeaderSame('Upload-Length', (string) strlen($content));

        // Falscher Offset (doppelt gesendetes Stück) → 409 mit richtigem Stand.
        $this->patch($guest, $id, 0, substr($content, 0, 1000));
        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Upload-Offset', '1000');

        $this->patch($guest, $id, 1000, substr($content, 1000));
        self::assertResponseStatusCodeSame(201);
        self::assertSame('ready', $this->field('media', 'processing'));

        $media = $this->media($this->field('media', 'id'));
        self::assertSame('image/jpeg', $media->getOriginalMimeType());
        self::assertSame(strlen($content), $media->getOriginalBytes());
        self::assertSame([800, 600], [$media->getWidth(), $media->getHeight()]);
        $original = self::service(MediaStorage::class)->localPath($media->mediaId(), MediaFile::Original);
        self::assertSame($content, file_get_contents($original), 'Datei muss unverändert ankommen.');

        $upload = self::service(UploadRepository::class)->find(Uuid::fromString($id));
        self::assertInstanceOf(Upload::class, $upload);
        self::assertFileDoesNotExist(
            self::service(UploadBuffer::class)->path($upload),
            'Puffer muss nach Abschluss leer sein.',
        );
    }

    public function testSizeLimitsAreEnforcedOnServer(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest, $secret] = $this->fixtures->guest($wedding);
        $this->actAsGuest($guest, $secret);

        $this->startRaw($guest, ['type' => 'photo', 'size' => (30 * 1024 * 1024) + 1]);
        self::assertResponseStatusCodeSame(413);
        self::assertStringContainsString('zu groß', $this->field('error'));

        $this->startRaw($guest, ['type' => 'video', 'size' => (500 * 1024 * 1024) + 1]);
        self::assertResponseStatusCodeSame(413);

        $this->startRaw($guest, ['type' => 'photo', 'size' => 0]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testWeddingAndGuestQuotasIncludeReservations(): void
    {
        $wedding = $this->fixtures->wedding();
        $wedding->limits()->change(120, 1000, 1000, 2500, 1500);
        $this->fixtures->flush();
        [$guest, $secret] = $this->fixtures->guest($wedding);
        [$other, $otherSecret] = $this->fixtures->guest($wedding, 'Andere');
        $this->actAsGuest($guest, $secret);

        $this->start($guest, 'photo', 1000);
        $this->startRaw($guest, ['type' => 'photo', 'size' => 600]);
        self::assertResponseStatusCodeSame(507, 'Gastkontingent 1500 inkl. Reservierung 1000');
        self::assertStringContainsString('Kontingent', $this->field('error'));

        $this->client->getCookieJar()->clear();
        $this->actAsGuest($other, $otherSecret);
        $this->start($other, 'photo', 1000);
        $this->startRaw($other, ['type' => 'photo', 'size' => 600]);
        self::assertResponseStatusCodeSame(507, 'Hochzeitskontingent 2500 inkl. beider Reservierungen');
        self::assertStringContainsString('Speicher dieser Hochzeit', $this->field('error'));
    }

    public function testParallelUploadsPerGuestAreLimited(): void
    {
        [$guest, $secret] = $this->fixtures->guest($this->fixtures->wedding());
        $this->actAsGuest($guest, $secret);

        for ($i = 0; $i < 3; ++$i) {
            $this->start($guest, 'photo', 1000);
        }

        $this->startRaw($guest, ['type' => 'photo', 'size' => 1000]);
        self::assertResponseStatusCodeSame(429);
    }

    public function testChunkLargerThanAnnouncedIsRejectedWithoutCorruption(): void
    {
        [$guest, $secret] = $this->fixtures->guest($this->fixtures->wedding());
        $this->actAsGuest($guest, $secret);
        $id = $this->start($guest, 'photo', 1000);

        $this->patch($guest, $id, 0, str_repeat('x', 1001));
        self::assertResponseStatusCodeSame(413);

        $this->client->request('HEAD', $this->uploadUrl($guest, $id));
        self::assertResponseHeaderSame('Upload-Offset', '0');
    }

    public function testDisguisedFileIsRejectedAndRemoved(): void
    {
        [$guest, $secret] = $this->fixtures->guest($this->fixtures->wedding());
        $this->actAsGuest($guest, $secret);
        $payload = "<?php echo 'kein Bild';";
        $id = $this->start($guest, 'photo', strlen($payload));

        $this->patch($guest, $id, 0, $payload);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Bildformat', $this->field('error'));
        self::assertSame(0, self::service(MediaRepository::class)->count(['uploaderGuest' => $guest]));
        self::assertNull(self::service(UploadRepository::class)->find(Uuid::fromString($id)));
    }

    public function testPhotoDeclaredAsVideoIsRejected(): void
    {
        [$guest, $secret] = $this->fixtures->guest($this->fixtures->wedding());
        $this->actAsGuest($guest, $secret);
        $content = (string) file_get_contents(self::PHOTO);
        $id = $this->start($guest, 'video', strlen($content));

        $this->patch($guest, $id, 0, $content);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Videoformat', $this->field('error'));
    }

    public function testVideoIsTranscodedByWorkerAndBecomesReady(): void
    {
        $wedding = $this->fixtures->wedding(ModerationMode::Approval);
        [$guest, $secret] = $this->fixtures->guest($wedding);
        $this->actAsGuest($guest, $secret);
        $content = (string) file_get_contents(VideoFixtures::landscapeH264());

        $id = $this->start($guest, 'video', strlen($content));
        $this->patch($guest, $id, 0, $content);
        self::assertResponseStatusCodeSame(201);

        // In Tests läuft der Worker synchron; der Status kommt über die Liste.
        $this->client->request('GET', "/h/{$wedding->getSlug()}/api/media/mine");
        self::assertResponseIsSuccessful();
        self::assertSame('ready', $this->field('items', '0', 'processing'));
        self::assertSame(
            'pending',
            $this->field('items', '0', 'publication'),
            'Moderationsmodus „Freigabe“ gilt auch für Uploads.',
        );
        self::assertStringStartsWith('/media/', $this->field('items', '0', 'videoUrl'));
        self::assertStringStartsWith('/media/', $this->field('items', '0', 'thumbUrl'));

        $media = $this->media($this->field('items', '0', 'id'));
        $storage = self::service(MediaStorage::class);
        self::assertTrue($storage->exists($media->mediaId(), MediaFile::Video));
        self::assertTrue($storage->exists($media->mediaId(), MediaFile::Poster));
        self::assertGreaterThan(0, $media->getDerivedBytes());
        self::assertEqualsWithDelta(4.0, (float) $media->getDurationSeconds(), 0.5);
    }

    public function testTooLongVideoIsRejectedImmediately(): void
    {
        $wedding = $this->fixtures->wedding();
        $wedding->limits()->change(2, 500 * 1024 * 1024, 1024 * 1024, 1024 ** 3, null);
        $this->fixtures->flush();
        [$guest, $secret] = $this->fixtures->guest($wedding);
        $this->actAsGuest($guest, $secret);
        $content = (string) file_get_contents(VideoFixtures::landscapeH264());

        $id = $this->start($guest, 'video', strlen($content));
        $this->patch($guest, $id, 0, $content);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('höchstens 2 Sekunden', $this->field('error'));
        self::assertSame(0, self::service(MediaRepository::class)->count(['uploaderGuest' => $guest]));
    }

    public function testUploadsOfOtherGuestsAreInvisible(): void
    {
        $wedding = $this->fixtures->wedding();
        [$owner, $ownerSecret] = $this->fixtures->guest($wedding, 'Owner');
        [$other, $otherSecret] = $this->fixtures->guest($wedding, 'Andere');
        $this->actAsGuest($owner, $ownerSecret);
        $id = $this->start($owner, 'photo', 1000);

        $this->client->getCookieJar()->clear();
        $this->actAsGuest($other, $otherSecret);
        $this->client->request('HEAD', $this->uploadUrl($other, $id));
        self::assertResponseStatusCodeSame(404);
        $this->patch($other, $id, 0, 'x');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('DELETE', $this->uploadUrl($other, $id), server: self::headers());
        self::assertResponseStatusCodeSame(404);
    }

    public function testCrossSiteRequestsAreRejected(): void
    {
        [$guest, $secret] = $this->fixtures->guest($this->fixtures->wedding());
        $this->actAsGuest($guest, $secret);
        $url = "/h/{$guest->getWedding()->getSlug()}/api/uploads";
        $body = (string) json_encode(['type' => 'photo', 'size' => 10]);

        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        self::assertResponseStatusCodeSame(403, 'ohne eigenen Header');

        $this->client->request(
            'POST',
            $url,
            server: self::headers() + ['HTTP_ORIGIN' => 'https://boese.example'],
            content: $body,
        );
        self::assertResponseStatusCodeSame(403, 'fremde Origin');

        $this->client->request(
            'POST',
            $url,
            server: self::headers() + ['HTTP_SEC_FETCH_SITE' => 'cross-site'],
            content: $body,
        );
        self::assertResponseStatusCodeSame(403, 'Sec-Fetch-Site cross-site');
    }

    public function testClosedModuleArchivedWeddingAndAnonymousCannotUpload(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest, $secret] = $this->fixtures->guest($wedding);

        $this->startRaw($guest, ['type' => 'photo', 'size' => 10]);
        self::assertResponseStatusCodeSame(401, 'ohne Gastzugang');

        $this->actAsGuest($guest, $secret);
        self::fresh($wedding)->module(ModuleType::Gallery)->closeContributionsAt(new DateTimeImmutable('-1 minute'));
        $this->fixtures->flush();
        $this->startRaw($guest, ['type' => 'photo', 'size' => 10]);
        self::assertResponseStatusCodeSame(403);

        $fresh = self::fresh($wedding);
        $fresh->module(ModuleType::Gallery)->closeContributionsAt(null);
        $fresh->archive();
        $this->fixtures->flush();
        $this->startRaw($guest, ['type' => 'photo', 'size' => 10]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAbortRemovesBufferAndReservation(): void
    {
        [$guest, $secret] = $this->fixtures->guest($this->fixtures->wedding());
        $this->actAsGuest($guest, $secret);
        $id = $this->start($guest, 'photo', 2000);
        $this->patch($guest, $id, 0, str_repeat('x', 500));
        $upload = self::service(UploadRepository::class)->find(Uuid::fromString($id));
        self::assertInstanceOf(Upload::class, $upload);
        $buffer = self::service(UploadBuffer::class)->path($upload);
        self::assertFileExists($buffer);

        $this->client->request('DELETE', $this->uploadUrl($guest, $id), server: self::headers());

        self::assertResponseStatusCodeSame(204);
        self::assertFileDoesNotExist($buffer);
        self::assertNull(self::service(UploadRepository::class)->find(Uuid::fromString($id)));
    }

    public function testGuestDeletesOwnMediaWithAllFiles(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest, $secret] = $this->fixtures->guest($wedding);
        [$other, $otherSecret] = $this->fixtures->guest($wedding, 'Andere');
        $this->actAsGuest($guest, $secret);
        $content = (string) file_get_contents(self::PHOTO);
        $id = $this->start($guest, 'photo', strlen($content));
        $this->patch($guest, $id, 0, $content);
        $mediaId = $this->field('media', 'id');
        $media = $this->media($mediaId);
        $storage = self::service(MediaStorage::class);

        $this->client->getCookieJar()->clear();
        $this->actAsGuest($other, $otherSecret);
        $this->client->request('DELETE', "/h/{$wedding->getSlug()}/api/media/{$mediaId}", server: self::headers());
        self::assertResponseStatusCodeSame(403, 'Andere Gäste sehen das Foto, dürfen es aber nicht löschen.');

        $this->client->getCookieJar()->clear();
        $this->actAsGuest($guest, $secret);
        $this->client->request('DELETE', "/h/{$wedding->getSlug()}/api/media/{$mediaId}", server: self::headers());

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $storage->list($media->mediaId()));
        self::assertTrue($this->media($mediaId)->isDeleted());
    }

    public function testUploadPageRendersForGuests(): void
    {
        [$guest, $secret] = $this->fixtures->guest($this->fixtures->wedding());
        $this->actAsGuest($guest, $secret);

        $this->client->request('GET', "/h/{$guest->getWedding()->getSlug()}/upload");

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="upload"]');
        self::assertSelectorTextContains('.muted', '30 MB');
    }

    private function start(Guest $guest, string $type, int $size): string
    {
        $this->startRaw($guest, ['type' => $type, 'size' => $size]);
        self::assertResponseStatusCodeSame(201);

        return $this->field('id');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function startRaw(Guest $guest, array $payload): void
    {
        $this->client->request(
            'POST',
            "/h/{$guest->getWedding()->getSlug()}/api/uploads",
            server: self::headers() + ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode($payload),
        );
    }

    private function patch(Guest $guest, string $id, int $offset, string $chunk): void
    {
        $this->client->request(
            'PATCH',
            $this->uploadUrl($guest, $id),
            server: self::headers()
            + [
                'HTTP_UPLOAD_OFFSET' => (string) $offset,
                'CONTENT_TYPE' => 'application/offset+octet-stream',
            ],
            content: $chunk,
        );
    }

    private function uploadUrl(Guest $guest, string $id): string
    {
        return "/h/{$guest->getWedding()->getSlug()}/api/uploads/{$id}";
    }

    /**
     * @return array<string, string>
     */
    private static function headers(): array
    {
        return ['HTTP_X_REQUESTED_WITH' => 'wedora'];
    }

    /**
     * Liest einen Text-Wert aus der JSON-Antwort, z. B. field('media', 'id').
     */
    private function field(string ...$path): string
    {
        // @mago-expect analysis:mixed-assignment
        $value = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        foreach ($path as $key) {
            self::assertIsArray($value, implode('.', $path));
            self::assertArrayHasKey($key, $value, implode('.', $path));
            // @mago-expect analysis:mixed-assignment
            $value = $value[$key];
        }

        self::assertIsString($value, implode('.', $path));

        return $value;
    }

    private function media(string $id): Media
    {
        $media = self::service(MediaRepository::class)->find(Uuid::fromString($id));
        self::assertInstanceOf(Media::class, $media);
        self::em()->refresh($media);

        return $media;
    }
}
