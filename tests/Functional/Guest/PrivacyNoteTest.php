<?php

declare(strict_types=1);

namespace App\Tests\Functional\Guest;

use App\Account\WeddingPermission;
use App\Tests\Functional\WebTestCase;
use App\Wedding\Host\HostPronoun;
use App\Wedding\Host\WeddingPicture;
use App\Wedding\Host\WeddingPictureStorage;
use App\Wedding\WeddingEraser;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\Filesystem\Filesystem;

final class PrivacyNoteTest extends WebTestCase
{
    protected function tearDown(): void
    {
        $root = $_SERVER['MEDIA_ROOT'] ?? null;
        new Filesystem()->remove(is_string($root) ? $root : '/tmp/wedora-test-media');
        parent::tearDown();
    }

    public function testWithoutHostGuestsSeeAGeneralNote(): void
    {
        $wedding = $this->fixtures->wedding();
        $this->client->request('GET', "/h/{$wedding->getSlug()}/join?k={$wedding->access()->openLinkToken()}");
        $this->client->followRedirect();
        self::assertSelectorTextContains('.privacy-note', 'auf einem privaten Server – nicht bei Google');
        self::assertSelectorNotExists('img.privacy-note__photo');

        $this->client->request('GET', "/h/{$wedding->getSlug()}/join/gastgeber.jpg");
        self::assertResponseStatusCodeSame(404);
    }

    public function testCoupleNamesTheHostWithPhotoAndGuestsSeeItEverywhere(): void
    {
        $wedding = $this->fixtures->wedding();
        $user = $this->fixtures->user();
        $this->fixtures->member($user, self::fresh($wedding), [WeddingPermission::Configure]);
        $this->client->loginUser(self::fresh($user));

        $crawler = $this->client->request('GET', "/admin/h/{$wedding->getSlug()}/einstellungen");
        $form = $crawler
            ->selectButton('Hinweis speichern')
            ->form([
                'host_name' => 'Wolfgang',
                'host_note' => 'sitzt an Tisch 3',
                'host_pronoun' => 'er',
                'server_note' => 'ein Lenovo ThinkCentre in seiner Wohnung in Köln-Ehrenfeld',
            ]);
        foreach (['host_photo', 'server_photo'] as $name) {
            $copy = sys_get_temp_dir() . '/host-' . bin2hex(random_bytes(4)) . '.jpg';
            copy(__DIR__ . '/../../Fixtures/phase0.jpg', $copy);
            // @mago-expect analysis:mixed-assignment
            $field = $form[$name];
            self::assertInstanceOf(FileFormField::class, $field);
            $field->upload($copy);
        }

        $this->client->submit($form);
        self::assertResponseRedirects();

        $wedding = self::fresh($wedding);
        self::assertSame('Wolfgang', $wedding->getPresentation()->getHostName());
        self::assertNotNull($wedding->getPresentation()->getHostPhotoAt());
        self::assertNotNull($wedding->getPresentation()->getServerPhotoAt());
        self::assertSame(HostPronoun::He, $wedding->getPresentation()->getHostPronoun());
        $storage = self::service(WeddingPictureStorage::class);
        $path = $storage->path($wedding, WeddingPicture::Host);
        $serverPath = $storage->path($wedding, WeddingPicture::Server);
        $size = getimagesize($path);
        self::assertNotFalse($size);
        self::assertSame([320, 320], [$size[0], $size[1]], 'Gastgeber quadratisch.');
        self::assertFileExists($serverPath);
        $serverUrl = "/h/{$wedding->getSlug()}/bild/server.jpg";

        // Beitrittsseite: noch kein Gast, Hinweis mit Foto trotzdem sichtbar.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', "/h/{$wedding->getSlug()}/join?k={$wedding->access()->openLinkToken()}");
        $this->client->followRedirect();
        self::assertSelectorTextContains('.privacy-note', 'eigenen Server von Wolfgang, sitzt an Tisch 3');
        self::assertSelectorExists('img.privacy-note__photo');
        $this->client->request('GET', "/h/{$wedding->getSlug()}/join/gastgeber.jpg");
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');
        $this->client->request('GET', $serverUrl);
        self::assertFalse($this->client->getResponse()->isSuccessful(), 'Server-Foto nur für Gäste.');

        [$guest, $secret] = $this->fixtures->guest(self::fresh($wedding));
        $this->actAsGuest($guest, $secret);
        $this->client->request('GET', "/h/{$wedding->getSlug()}");
        self::assertSelectorTextContains('.privacy-note a', 'Mehr dazu');
        $this->client->request('GET', "/h/{$wedding->getSlug()}/privatsphaere");
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.host-card', 'Wolfgang');
        self::assertSelectorTextContains('.host-card', 'Sprich ihn gern an');
        self::assertSelectorTextContains('.server-card', 'Ein Lenovo ThinkCentre in seiner Wohnung in Köln-Ehrenfeld.');
        self::assertSelectorExists('.server-card img[src^="' . $serverUrl . '"]');
        self::assertSelectorTextContains('.privacy-facts', 'Nur für diese Hochzeit');
        self::assertSelectorTextNotContains('.privacy-facts', 'Gemini', 'Ohne Porträts keine Gemini-Ausnahme.');
        $this->client->request('GET', $serverUrl);
        self::assertResponseIsSuccessful();

        self::service(WeddingEraser::class)->erase(self::fresh($wedding));
        self::assertFileDoesNotExist($path);
        self::assertFileDoesNotExist($serverPath);
    }
}
