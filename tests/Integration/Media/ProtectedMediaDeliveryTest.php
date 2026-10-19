<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media;

use App\Guest\Entity\Guest;
use App\Media\Access\MediaAccessTokenSigner;
use App\Media\Entity\Media;
use App\Media\Image\ImgproxyUrlSigner;
use App\Media\MediaId;
use App\Media\MediaType;
use App\Media\Storage\LocalMediaStorage;
use App\Media\Storage\MediaFile;
use App\Media\Storage\MediaStorage;
use App\Media\Video\FfmpegVideoTranscoder;
use App\Media\Visibility;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\Fixtures;
use App\Wedding\Entity\Wedding;
use App\Wedding\ModuleType;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Nachweis: private Medien sind nur nach serverseitiger Prüfung abrufbar.
 *
 * Läuft gegen den echten Stack (Caddy → Symfony → Caddy/imgproxy, Medien-Volume): make up && make test-media
 * Der Webserver arbeitet in der dev-Umgebung; die Testdaten werden deshalb in deren Datenbank
 * und im echten Medien-Volume angelegt und nach jedem Test wieder entfernt.
 */
#[Group('media')]
final class ProtectedMediaDeliveryTest extends IntegrationTestCase
{
    private const string WEB = 'http://web';
    private const string MEDIA_VOLUME = '/srv/media';

    private HttpClientInterface $http;
    private MediaStorage $storage;
    private MediaAccessTokenSigner $tokens;
    private Fixtures $fixtures;
    private Wedding $wedding;
    private Guest $guest;

    /** @var list<MediaId> */
    private array $created = [];

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'dev', 'debug' => false]);
        $this->http = HttpClient::create(['timeout' => 15]);
        $this->storage = new LocalMediaStorage(self::MEDIA_VOLUME);
        $this->tokens = new MediaAccessTokenSigner(self::env('MEDIA_TOKEN_SECRET'), new NativeClock());
        $this->fixtures = new Fixtures(self::devEntityManager(...));
        $this->wedding = $this->fixtures->wedding();
        [$this->guest] = $this->fixtures->guest($this->wedding, 'E2E');
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            $this->storage->deleteAll($id);
        }

        self::removeWedding($this->wedding);

        parent::tearDown();
    }

    public function testAuthorizedGuestGetsVideoWithRangeSupport(): void
    {
        $id = $this->createVideo();
        $token = $this->grantAndIssue($id);

        $full = $this->get("/media/{$token}/video.mp4");
        self::assertSame(200, $full['status']);
        self::assertSame('video/mp4', $full['headers']['content-type'][0] ?? null);
        self::assertSame($this->storage->size($id, MediaFile::Video), strlen($full['body']));
        self::assertPrivateNoStore($full['headers']);
        self::assertArrayNotHasKey(
            'x-accel-redirect',
            $full['headers'],
            'Interner Pfad darf nicht nach außen dringen.',
        );

        $partial = $this->get("/media/{$token}/video.mp4", ['Range' => 'bytes=0-99']);
        self::assertSame(206, $partial['status'], 'Range-Requests sind für Vorspulen im Browser nötig.');
        self::assertSame(100, strlen($partial['body']));
    }

    public function testImagesAreDeliveredOnlyAsFixedPresetsViaImgproxy(): void
    {
        $image = $this->createImage();
        $video = $this->createVideo();
        $imageToken = $this->grantAndIssue($image);
        $videoToken = $this->grantAndIssue($video);

        $thumb = $this->get("/media/{$imageToken}/image/thumb");
        self::assertSame(200, $thumb['status']);
        self::assertSame('image/webp', $thumb['headers']['content-type'][0] ?? null);
        $size = getimagesizefromstring($thumb['body']);
        self::assertNotFalse($size);
        self::assertLessThanOrEqual(720, max($size[0], $size[1]));
        self::assertPrivateNoStore($thumb['headers']);

        self::assertSame(200, $this->get("/media/{$imageToken}/image/display")['status']);
        self::assertSame(
            200,
            $this->get("/media/{$videoToken}/image/thumb")['status'],
            'Video-Vorschau aus Standbild.',
        );

        // Keine freien Transformationen, kein Original, kein Video für ein Bild.
        self::assertSame(404, $this->get("/media/{$imageToken}/image/huge")['status']);
        self::assertSame(404, $this->get("/media/{$imageToken}/original")['status']);
        self::assertSame(404, $this->get("/media/{$imageToken}/video.mp4")['status']);
    }

    public function testInvalidTokensAreRejectedForEveryVariant(): void
    {
        $id = $this->createVideo();
        $valid = $this->grantAndIssue($id);
        $secret = $_SERVER['MEDIA_TOKEN_SECRET'] ?? null;
        self::assertIsString($secret);
        $foreignWedding = $this->fixtures->wedding();
        [$foreign] = $this->fixtures->guest($foreignWedding, 'Fremd');

        $badTokens = [
            'ungueltig' => 'invalid.token',
            'abgelaufen' => new MediaAccessTokenSigner($secret, new MockClock('-1 hour'), 60)->issue(
                $id->value,
                $this->subject(),
            ),
            'fremdes Secret' => new MediaAccessTokenSigner(str_repeat('x', 40), new MockClock(), 900)->issue(
                $id->value,
                $this->subject(),
            ),
            'manipuliert' => substr($valid, 0, -2) . (str_ends_with($valid, 'AA') ? 'BB' : 'AA'),
            'Gast einer anderen Hochzeit' => $this->tokens->issue(
                $id->value,
                'guest:' . $foreign->getId()->toRfc4122(),
            ),
            'unbekannter Betrachter' => $this->tokens->issue($id->value, 'guest:00000000-0000-7000-8000-000000000000'),
        ];

        try {
            foreach ($badTokens as $label => $token) {
                foreach (['video.mp4', 'image/thumb'] as $variant) {
                    self::assertSame(
                        403,
                        $this->get("/media/{$token}/{$variant}")['status'],
                        "{$label}: {$variant} muss gesperrt sein.",
                    );
                }
            }
        } finally {
            self::removeWedding($foreignWedding);
        }
    }

    public function testStorageAndImgproxyAreNotReachableDirectly(): void
    {
        $id = $this->createVideo();
        $relative = $this->storage->relativePath($id, MediaFile::Video);
        $signed = new ImgproxyUrlSigner(self::env('IMGPROXY_KEY'), self::env('IMGPROXY_SALT'))->sign(
            'local:///' . $this->storage->relativePath($id, MediaFile::Poster),
            ['rs:fit:100:100'],
        );

        foreach ([
            "/_protected/files/{$relative}",
            "/_protected/imgproxy{$signed}",
            "/index.php/_protected/files/{$relative}",
            "/{$relative}",
            "/media/{$relative}",
            '/media/x.y/../../_protected/files/' . $relative,
        ] as $path) {
            self::assertContains($this->get($path)['status'], [400, 404], "{$path} darf nicht ausliefern.");
        }
    }

    public function testRevocationAndDeletionTakeEffectImmediately(): void
    {
        $id = $this->createVideo();
        $token = $this->grantAndIssue($id);
        self::assertSame(206, $this->get("/media/{$token}/video.mp4", ['Range' => 'bytes=0-9'])['status']);

        // Gast wird gesperrt: auch die bereits laufende Wiedergabe endet.
        $em = self::devEntityManager();
        $em->find(Guest::class, $this->guest->getId())?->block(new DateTimeImmutable());
        $em->flush();
        self::assertSame(403, $this->get("/media/{$token}/video.mp4", ['Range' => 'bytes=10-19'])['status']);
        self::assertSame(403, $this->get("/media/{$token}/image/thumb")['status']);

        $em->find(Guest::class, $this->guest->getId())?->unblock();
        $em->flush();
        self::assertSame(206, $this->get("/media/{$token}/video.mp4", [
            'Range' => 'bytes=10-19',
        ])['status'], 'Kontrolle nach Entsperren');
        $this->storage->deleteAll($id);
        self::assertSame([], $this->storage->list($id), 'Original, Video und Standbild müssen entfernt sein.');
        self::assertSame(404, $this->get("/media/{$token}/video.mp4")['status']);
        self::assertSame(404, $this->get("/media/{$token}/image/thumb")['status']);
    }

    private function createVideo(): MediaId
    {
        $id = $this->newMedia(MediaType::Video);
        $this->storage->store($id, MediaFile::Original, VideoFixtures::landscapeH264());
        new FfmpegVideoTranscoder()->transcode(
            $this->storage->localPath($id, MediaFile::Original),
            $this->storage->localPath($id, MediaFile::Video),
            $this->storage->localPath($id, MediaFile::Poster),
        );

        return $id;
    }

    private function createImage(): MediaId
    {
        $id = $this->newMedia(MediaType::Photo);
        $this->storage->store($id, MediaFile::Original, dirname(__DIR__, 2) . '/Fixtures/phase0.jpg');

        return $id;
    }

    private function newMedia(MediaType $type): MediaId
    {
        $em = self::devEntityManager();
        $guest = $em->find(Guest::class, $this->guest->getId()) ?? throw new RuntimeException('Gast fehlt.');
        $media = Media::uploadedByGuest($guest, ModuleType::Gallery, $type, Visibility::AllGuests);
        $em->persist($media);
        $em->flush();
        $this->created[] = $media->mediaId();

        return $media->mediaId();
    }

    /** Medien-URL für den Test-Gast (Berechtigung ergibt sich aus der Domäne, nicht aus einer Freigabeliste). */
    private function grantAndIssue(MediaId $id): string
    {
        return $this->tokens->issue($id->value, $this->subject());
    }

    private function subject(): string
    {
        return 'guest:' . $this->guest->getId()->toRfc4122();
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string, headers: array<string, list<string>>}
     */
    private function get(string $path, array $headers = []): array
    {
        $response = $this->http->request('GET', self::WEB . $path, ['headers' => $headers, 'max_redirects' => 0]);

        return [
            'status' => $response->getStatusCode(),
            'body' => $response->getContent(false),
            'headers' => $response->getHeaders(false),
        ];
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private static function assertPrivateNoStore(array $headers): void
    {
        $cacheControl = implode(',', $headers['cache-control'] ?? []);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
    }

    /**
     * Löscht per SQL; die Fremdschlüssel (ON DELETE CASCADE) entfernen Gäste, Medien, Module mit.
     */
    private static function removeWedding(Wedding $wedding): void
    {
        $em = self::devEntityManager();
        $em->clear();
        $em->getConnection()->delete('wedding', ['id' => $wedding->getId()->toBinary()]);
    }

    /**
     * Der dev-Kernel hat keinen Test-Container; Doctrine ist dort öffentlich erreichbar.
     */
    private static function devEntityManager(): EntityManagerInterface
    {
        $doctrine = (self::$kernel ?? throw new RuntimeException('Kernel nicht gestartet.'))->getContainer()->get(
            'doctrine',
        );
        // @phpstan-ignore instanceof.alwaysTrue (Mago kennt den Container-Typ nicht)
        $em = $doctrine instanceof ManagerRegistry ? $doctrine->getManager() : null;

        return $em instanceof EntityManagerInterface ? $em : throw new RuntimeException('EntityManager fehlt.');
    }

    private static function env(string $name): string
    {
        $value = $_SERVER[$name] ?? null;

        return is_string($value) && '' !== $value ? $value : throw new RuntimeException("{$name} fehlt.");
    }
}
