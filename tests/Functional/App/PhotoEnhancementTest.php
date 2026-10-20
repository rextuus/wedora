<?php

declare(strict_types=1);

namespace App\Tests\Functional\App;

use App\Account\WeddingPermission;
use App\Media\Image\ImgproxyUrlSigner;
use App\Media\Image\PhotoEnhancer;
use App\Media\Repository\MediaRepository;
use App\Media\Storage\MediaFile;
use App\Media\Storage\MediaStorage;
use App\Tests\Functional\WebTestCase;
use App\Wedding\Entity\Wedding;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

/**
 * „Fotos automatisch angleichen“: Schalter in der Verwaltung, neue Uploads und vorhandene Fotos.
 * imgproxy wird durch ein festes Foto ersetzt, ffmpeg läuft echt (Ende-zu-Ende mit imgproxy: ProtectedMediaDeliveryTest).
 */
final class PhotoEnhancementTest extends WebTestCase
{
    protected function tearDown(): void
    {
        $root = $_SERVER['MEDIA_ROOT'] ?? null;
        new Filesystem()->remove(is_string($root) ? $root : '/tmp/wedora-test-media');
        parent::tearDown();
    }

    public function testSwitchingOnEnhancesNewAndExistingPhotosAndSwitchingOffRemovesThem(): void
    {
        $this->client->disableReboot();
        $photo = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/phase0.jpg');
        self::getContainer()->set(
            PhotoEnhancer::class,
            new PhotoEnhancer(
                new MockHttpClient(static fn(): MockResponse => new MockResponse($photo)),
                self::service(ImgproxyUrlSigner::class),
                self::service(MediaStorage::class),
                'http://imgproxy:8080',
            ),
        );
        $wedding = $this->fixtures->wedding();
        [$guest, $secret] = $this->fixtures->guest(self::fresh($wedding));
        $this->actAsGuest($guest, $secret);
        $existing = $this->upload($wedding, $photo);
        self::assertFalse($this->enhanced($existing), 'Standard: aus.');

        $this->switchEnhancement($wedding, true);
        self::assertTrue($this->enhanced($existing), 'Vorhandene Fotos werden nachgezogen.');
        $media = self::service(MediaRepository::class)->find(Uuid::fromString($existing));
        self::assertGreaterThan(0, $media?->getDerivedBytes(), 'Zählt zum Speicher.');

        $this->client->getCookieJar()->clear();
        $this->actAsGuest($guest, $secret);
        $new = $this->upload($wedding, $photo);
        self::assertTrue($this->enhanced($new), 'Neue Uploads gleich mit.');

        $this->switchEnhancement($wedding, false);
        self::assertFalse($this->enhanced($existing), 'Aus: wieder die Originale.');
        self::assertFalse($this->enhanced($new));
        self::assertSame(0, self::service(MediaRepository::class)->find(Uuid::fromString($new))?->getDerivedBytes());
    }

    private function switchEnhancement(Wedding $wedding, bool $on): void
    {
        $user = $this->fixtures->user();
        $this->fixtures->member($user, self::fresh($wedding), [WeddingPermission::Configure]);
        $this->client->getCookieJar()->clear();
        $this->client->loginUser(self::fresh($user));
        $crawler = $this->client->request('GET', "/admin/h/{$wedding->getSlug()}/einstellungen");
        $form = $crawler->selectButton('Foto-Look speichern')->form();
        $checkbox = $form->get('auto_enhance');
        self::assertInstanceOf(ChoiceFormField::class, $checkbox);
        if ($on) {
            $checkbox->tick();
        } else {
            $checkbox->untick();
        }

        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertSame($on, self::fresh($wedding)->getPresentation()->enhancesPhotos());
    }

    private function enhanced(string $mediaId): bool
    {
        $media = self::service(MediaRepository::class)->find(Uuid::fromString($mediaId));

        return null !== $media && self::service(MediaStorage::class)->exists($media->mediaId(), MediaFile::Enhanced);
    }

    private function upload(Wedding $wedding, string $content): string
    {
        $this->client->request(
            'POST',
            "/h/{$wedding->getSlug()}/api/uploads",
            server: ['HTTP_X_REQUESTED_WITH' => 'wedora', 'CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['type' => 'photo', 'size' => strlen($content)]),
        );
        self::assertResponseStatusCodeSame(201);
        /** @var array{id: string} $start */
        $start = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->client->request(
            'PATCH',
            "/h/{$wedding->getSlug()}/api/uploads/{$start['id']}",
            server: [
                'HTTP_X_REQUESTED_WITH' => 'wedora',
                'HTTP_UPLOAD_OFFSET' => '0',
                'CONTENT_TYPE' => 'application/offset+octet-stream',
            ],
            content: $content,
        );
        self::assertResponseStatusCodeSame(201);
        /** @var array{media: array{id: string}} $done */
        $done = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $done['media']['id'];
    }
}
