<?php

declare(strict_types=1);

namespace App\Tests\Functional\Guest;

use App\Guest\Portrait\PortraitStatus;
use App\Guest\Portrait\PortraitStorage;
use App\Guest\Repository\GuestRepository;
use App\Tests\Functional\WebTestCase;
use App\Tests\Support\FakeGemini;
use App\Wedding\Entity\Wedding;
use App\Wedding\WeddingEraser;
use SensitiveParameter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PortraitTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeGemini::reset();
    }

    protected function tearDown(): void
    {
        $root = $_SERVER['MEDIA_ROOT'] ?? null;
        new Filesystem()->remove(is_string($root) ? $root : '/tmp/wedora-test-media');
        parent::tearDown();
    }

    public function testNewGuestIsOfferedThePortraitRightAfterJoiningOnlyWhenEnabled(): void
    {
        $enabled = $this->weddingWithPortraits();
        $this->client->request('GET', "/h/{$enabled->getSlug()}/join?k={$enabled->access()->openLinkToken()}");
        $this->client->followRedirect();
        $this->client->submitForm('Los geht’s', ['display_name[displayName]' => 'Sophie']);
        self::assertResponseRedirects("/h/{$enabled->getSlug()}/portraet?willkommen=1");
        $this->client->followRedirect();
        self::assertSelectorTextContains('.overline', 'Willkommen, Sophie');
        self::assertSelectorTextContains('main', 'Überspringen');

        $disabled = $this->fixtures->wedding();
        $this->client->request('GET', "/h/{$disabled->getSlug()}/join?k={$disabled->access()->openLinkToken()}");
        $this->client->followRedirect();
        $this->client->submitForm('Los geht’s', ['display_name[displayName]' => 'Paul']);
        self::assertResponseRedirects("/h/{$disabled->getSlug()}");

        [$guest, $secret] = $this->fixtures->guest(self::fresh($disabled));
        $this->actAsGuest($guest, $secret);
        $this->client->request('GET', "/h/{$disabled->getSlug()}/portraet");
        self::assertResponseStatusCodeSame(404);
    }

    public function testSelfieBecomesDrawingAndIsDeletedAfterwards(): void
    {
        $wedding = $this->weddingWithPortraits();
        [$guest, $secret] = $this->fixtures->guest($wedding);
        $this->actAsGuest($guest, $secret);

        $this->submitSelfie($wedding);
        self::assertResponseRedirects("/h/{$wedding->getSlug()}");

        // Gemini bekam das Selfie samt Schlüssel im Header (nicht in der URL).
        self::assertCount(1, FakeGemini::$requests);
        $request = FakeGemini::$requests[0];
        self::assertStringContainsString('gemini-3.1-flash-image:generateContent', $request['url']);
        self::assertStringNotContainsString('test-only-not-a-real-key', $request['url']);
        self::assertStringContainsString('inline_data', (string) json_encode($request['options']));

        $guest = self::fresh($guest);
        self::assertSame(PortraitStatus::Ready, $guest->getPortraitStatus());
        $storage = self::service(PortraitStorage::class);
        self::assertFileExists($storage->portraitPath($guest));
        self::assertFileDoesNotExist(
            $storage->selfiePath($guest),
            'Das Selfie darf nach dem Zeichnen nicht liegen bleiben.',
        );
        self::assertSame('image/png', mime_content_type($storage->portraitPath($guest)));

        $this->client->request('GET', "/h/{$wedding->getSlug()}");
        self::assertSelectorExists('.portrait--ready .portrait-art');
        self::assertSelectorExists('.app-header__avatar--portrait');

        $this->client->request('GET', "/h/{$wedding->getSlug()}/portraet/bild");
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertStringContainsString(
            'private',
            (string) $this->client->getResponse()->headers->get('Cache-Control'),
        );
    }

    public function testPortraitIsOnlyVisibleToItsOwner(): void
    {
        $wedding = $this->weddingWithPortraits();
        [$owner, $ownerSecret] = $this->fixtures->guest($wedding, 'Sophie');
        [$other, $otherSecret] = $this->fixtures->guest($wedding, 'Paul');
        $this->actAsGuest($owner, $ownerSecret);
        $this->submitSelfie($wedding);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', "/h/{$wedding->getSlug()}/portraet/bild");
        self::assertFalse($this->client->getResponse()->isSuccessful());

        $this->actAsGuest($other, $otherSecret);
        $this->client->request('GET', "/h/{$wedding->getSlug()}/portraet/bild");
        self::assertResponseStatusCodeSame(404);
    }

    public function testConsentFormatAndFeatureSwitchAreCheckedOnTheServer(): void
    {
        $wedding = $this->weddingWithPortraits();
        [$guest, $secret] = $this->fixtures->guest($wedding);
        $this->actAsGuest($guest, $secret);

        $this->submitSelfie($wedding, consent: false);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.notice--error', 'an Google übertragen');

        $text = tempnam(sys_get_temp_dir(), 'selfie');
        file_put_contents((string) $text, 'kein Bild');
        $this->submitSelfie($wedding, file: (string) $text);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.notice--error', 'Foto');

        $token = $this->formToken($wedding);
        self::fresh($wedding)->enablePortraits(false);
        self::em()->flush();
        $this->submitSelfie($wedding, token: $token);
        self::assertResponseStatusCodeSame(422);

        self::assertSame([], FakeGemini::$requests);
        self::assertNull(self::fresh($guest)->getPortraitStatus());
    }

    public function testRefusedDrawingLeavesNoSelfieAndOffersRetry(): void
    {
        $wedding = $this->weddingWithPortraits();
        [$guest, $secret] = $this->fixtures->guest($wedding);
        $this->actAsGuest($guest, $secret);
        FakeGemini::$answer = FakeGemini::BLOCKED;

        $this->submitSelfie($wedding);

        $guest = self::fresh($guest);
        self::assertSame(PortraitStatus::Failed, $guest->getPortraitStatus());
        self::assertFileDoesNotExist(self::service(PortraitStorage::class)->selfiePath($guest));
        $this->client->request('GET', "/h/{$wedding->getSlug()}");
        self::assertSelectorTextContains('.portrait__note', 'Nochmal versuchen');
    }

    public function testGuestCanDeleteThePortraitAndErasingTheWeddingRemovesAll(): void
    {
        $wedding = $this->weddingWithPortraits();
        [$guest, $secret] = $this->fixtures->guest($wedding, 'Sophie');
        [$second, $secondSecret] = $this->fixtures->guest($wedding, 'Paul');
        $storage = self::service(PortraitStorage::class);

        $this->actAsGuest($guest, $secret);
        $this->submitSelfie($wedding);
        $path = $storage->portraitPath(self::fresh($guest));
        self::assertFileExists($path);

        $crawler = $this->client->request('GET', "/h/{$wedding->getSlug()}/portraet");
        $this->client->submit($crawler->selectButton('Löschen')->form());
        self::assertResponseRedirects("/h/{$wedding->getSlug()}");
        self::assertFileDoesNotExist($path);
        self::assertNull(self::fresh($guest)->getPortraitStatus());

        $this->actAsGuest($second, $secondSecret);
        $this->submitSelfie($wedding);
        $secondPath = $storage->portraitPath(self::fresh($second));
        self::assertFileExists($secondPath);

        self::service(WeddingEraser::class)->erase(self::fresh($wedding));
        self::assertFileDoesNotExist($secondPath);
        self::assertDirectoryDoesNotExist(dirname($secondPath));
        self::assertNull(self::service(GuestRepository::class)->find($second->getId()));
    }

    private function weddingWithPortraits(): Wedding
    {
        $wedding = $this->fixtures->wedding();
        $wedding->enablePortraits(true);
        $this->fixtures->flush();

        return $wedding;
    }

    private function formToken(Wedding $wedding): string
    {
        $crawler = $this->client->request('GET', "/h/{$wedding->getSlug()}/portraet");

        return (string) $crawler->filter('form[enctype] input[name="_token"]')->attr('value');
    }

    private function submitSelfie(
        Wedding $wedding,
        bool $consent = true,
        ?string $file = null,
        #[SensitiveParameter]
        ?string $token = null,
    ): void {
        $token ??= $this->formToken($wedding);
        $source = $file ?? __DIR__ . '/../../Fixtures/phase0.jpg';
        // Kopie, weil der Upload die Datei verschieben darf.
        $copy = sys_get_temp_dir() . '/selfie-' . bin2hex(random_bytes(4));
        copy($source, $copy);

        $this->client->request(
            'POST',
            "/h/{$wedding->getSlug()}/portraet",
            ['_token' => $token, ...($consent ? ['consent' => '1'] : [])],
            ['selfie' => new UploadedFile($copy, 'selfie.jpg', null, null, true)],
        );
    }
}
