<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Account\WeddingPermission;
use App\Media\Access\MediaAccessTokenSigner;
use App\Media\Access\MediaUrlGenerator;
use App\Media\Entity\Media;
use App\Media\MediaType;
use App\Media\Storage\MediaFile;
use App\Media\Storage\MediaStorage;
use App\Media\Visibility;
use App\Security\Viewer;
use App\Tests\Functional\WebTestCase;
use App\Wedding\ModerationMode;
use DateTimeImmutable;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Medienabruf über HTTP mit der echten Berechtigungsprüfung (Kernel, Datenbank).
 * Erlaubt = 200 mit X-Accel-Redirect auf den internen Pfad; sonst 403.
 */
final class MediaAuthorizationTest extends WebTestCase
{
    protected function tearDown(): void
    {
        $root = $_SERVER['MEDIA_ROOT'] ?? null;
        new Filesystem()->remove(is_string($root) ? $root : '/tmp/wedora-test-media');
        parent::tearDown();
    }

    public function testGuestSeesOwnPendingUploadButOthersOnlyAfterApproval(): void
    {
        $wedding = $this->fixtures->wedding(ModerationMode::Approval);
        [$uploader] = $this->fixtures->guest($wedding, 'Uploader');
        [$other] = $this->fixtures->guest($wedding, 'Andere');
        $media = $this->photo($this->fixtures->guestMedia($uploader));

        $this->assertAllowed(Viewer::guest($uploader->getId(), $wedding->getId()), $media);
        $this->assertDenied(Viewer::guest($other->getId(), $wedding->getId()), $media);

        self::fresh($media)->approve(new DateTimeImmutable());
        $this->fixtures->flush();

        $this->assertAllowed(Viewer::guest($other->getId(), $wedding->getId()), $media);
    }

    public function testChangingVisibilityTakesEffectForAlreadyIssuedUrls(): void
    {
        $wedding = $this->fixtures->wedding();
        [$uploader] = $this->fixtures->guest($wedding, 'Uploader');
        [$other] = $this->fixtures->guest($wedding, 'Andere');
        $media = $this->photo($this->fixtures->guestMedia($uploader));
        $url = $this->url(Viewer::guest($other->getId(), $wedding->getId()), $media, 'image/thumb');

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        self::fresh($media)->changeVisibility(Visibility::UploaderOnly);
        $this->fixtures->flush();

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(
            403,
            'Bereits ausgegebene URL darf nach Sichtbarkeitswechsel nichts mehr liefern.',
        );
    }

    public function testCoupleVisibilityIsHiddenFromGuestsButVisibleToCouple(): void
    {
        $wedding = $this->fixtures->wedding();
        [$uploader] = $this->fixtures->guest($wedding, 'Uploader');
        [$other] = $this->fixtures->guest($wedding, 'Andere');
        $couple = $this->fixtures->user();
        $this->fixtures->member($couple, $wedding, [WeddingPermission::ViewContent]);
        $platformAdmin = $this->fixtures->user(true);
        $media = $this->video($this->fixtures->guestMedia($uploader, Visibility::Couple, MediaType::Video));

        $this->assertAllowed(Viewer::guest($uploader->getId(), $wedding->getId()), $media, 'video.mp4');
        $this->assertDenied(Viewer::guest($other->getId(), $wedding->getId()), $media, 'video.mp4');
        $this->assertAllowed(Viewer::user($couple->getId(), false, []), $media, 'video.mp4');
        $this->assertDenied(Viewer::user($platformAdmin->getId(), true, []), $media, 'video.mp4');
    }

    public function testBlockedGuestsAndRevokedInvitationsLoseMediaAccess(): void
    {
        $wedding = $this->fixtures->wedding();
        [$invitation] = $this->fixtures->invitation($wedding);
        [$invited] = $this->fixtures->guest($wedding, 'Eingeladen', $invitation);
        [$blocked] = $this->fixtures->guest($wedding, 'Gesperrt');
        [$uploader] = $this->fixtures->guest($wedding, 'Uploader');
        $media = $this->photo($this->fixtures->guestMedia($uploader));
        $invitedUrl = $this->url(Viewer::guest($invited->getId(), $wedding->getId()), $media, 'image/thumb');
        $blockedUrl = $this->url(Viewer::guest($blocked->getId(), $wedding->getId()), $media, 'image/thumb');

        foreach ([$invitedUrl, $blockedUrl] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful('Kontrolle vor Sperre/Widerruf');
        }

        self::fresh($invitation)->revoke(new DateTimeImmutable());
        self::fresh($blocked)->block(new DateTimeImmutable());
        $this->fixtures->flush();

        foreach ([$invitedUrl, $blockedUrl] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testGuestsOfOtherWeddingsAndDeletedMediaAreDenied(): void
    {
        $wedding = $this->fixtures->wedding();
        [$uploader] = $this->fixtures->guest($wedding);
        [$foreign] = $this->fixtures->guest($this->fixtures->wedding(), 'Fremd');
        $media = $this->photo($this->fixtures->guestMedia($uploader));

        $this->assertDenied(Viewer::guest($foreign->getId(), $foreign->getWedding()->getId()), $media);

        self::fresh($media)->markDeleted(new DateTimeImmutable());
        $this->fixtures->flush();
        $this->assertDenied(Viewer::guest($uploader->getId(), $wedding->getId()), $media);
    }

    public function testModeratorSeesPendingButNotPrivateUploads(): void
    {
        $wedding = $this->fixtures->wedding(ModerationMode::Approval);
        [$uploader] = $this->fixtures->guest($wedding);
        $moderator = $this->fixtures->user();
        $this->fixtures->member($moderator, $wedding, [WeddingPermission::Moderate]);
        $pending = $this->photo($this->fixtures->guestMedia($uploader));
        $private = $this->photo($this->fixtures->guestMedia($uploader, Visibility::UploaderOnly));
        $viewer = Viewer::user($moderator->getId(), false, []);

        $this->assertAllowed($viewer, $pending);
        $this->assertDenied($viewer, $private);
    }

    public function testSubjectInTokenCannotBeSwappedAndUnknownMediaIsDenied(): void
    {
        $wedding = $this->fixtures->wedding();
        [$uploader] = $this->fixtures->guest($wedding);
        $media = $this->photo($this->fixtures->guestMedia($uploader, Visibility::UploaderOnly));
        $signer = self::service(MediaAccessTokenSigner::class);

        foreach ([
            $signer->issue($media->getId()->toRfc4122(), 'guest:00000000-0000-7000-8000-000000000000'),
            $signer->issue($media->getId()->toRfc4122(), 'admin:alles'),
            $signer->issue('00000000-0000-7000-8000-000000000000', 'guest:' . $uploader->getId()->toRfc4122()),
            $signer->issue('../../etc', 'guest:' . $uploader->getId()->toRfc4122()),
        ] as $token) {
            $this->client->request('GET', "/media/{$token}/image/thumb");
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testAllowedButMissingFileIs404(): void
    {
        [$uploader] = $this->fixtures->guest($this->fixtures->wedding());
        $media = $this->photo($this->fixtures->guestMedia($uploader));

        $this->client->request('GET', $this->url(
            Viewer::guest($uploader->getId(), $uploader->getWedding()->getId()),
            $media,
            'video.mp4',
        ));

        self::assertResponseStatusCodeSame(404);
    }

    private function assertAllowed(Viewer $viewer, Media $media, string $variant = 'image/thumb'): void
    {
        $this->client->request('GET', $this->url($viewer, $media, $variant));

        self::assertResponseIsSuccessful();
        $redirect = (string) $this->client->getResponse()->headers->get('X-Accel-Redirect');
        $relative = self::service(MediaStorage::class)->relativePath($media->mediaId(), MediaFile::Video);
        self::assertStringStartsWith('/_protected/', $redirect);
        self::assertTrue(
            'video.mp4' === $variant
                ? str_ends_with($redirect, $relative)
                : str_starts_with($redirect, '/_protected/imgproxy/'),
            "Unerwartetes Ziel {$redirect}",
        );
    }

    private function assertDenied(Viewer $viewer, Media $media, string $variant = 'image/thumb'): void
    {
        $this->client->request('GET', $this->url($viewer, $media, $variant));

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->client->getResponse()->headers->has('X-Accel-Redirect'));
    }

    private function url(Viewer $viewer, Media $media, string $variant): string
    {
        $generator = new MediaUrlGenerator(
            self::service(MediaAccessTokenSigner::class),
            self::service(UrlGeneratorInterface::class),
        );

        return $generator->generate($viewer, $media, $variant);
    }

    private function photo(Media $media): Media
    {
        $storage = self::service(MediaStorage::class);
        $storage->store($media->mediaId(), MediaFile::Original, dirname(__DIR__, 2) . '/Fixtures/phase0.jpg');

        return $media;
    }

    private function video(Media $media): Media
    {
        $storage = self::service(MediaStorage::class);
        $fixture = dirname(__DIR__, 2) . '/Fixtures/phase0.jpg';
        $storage->store($media->mediaId(), MediaFile::Original, $fixture);
        $storage->store($media->mediaId(), MediaFile::Video, $fixture);
        $storage->store($media->mediaId(), MediaFile::Poster, $fixture);

        return $media;
    }
}
