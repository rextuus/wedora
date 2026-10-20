<?php

declare(strict_types=1);

namespace App\Tests\Functional\Guest;

use App\Account\WeddingPermission;
use App\Guest\Portrait\PortraitGadget;
use App\Guest\Portrait\PortraitStatus;
use App\Guest\Portrait\PortraitStorage;
use App\Guest\Portrait\PortraitStyle;
use App\Guest\Portrait\PortraitStyleMode;
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
        self::assertResponseRedirects("/h/{$enabled->getSlug()}/codewort?weiter=portraet");
        $this->client->followRedirect();
        self::assertSelectorExists('a.button[href="/h/' . $enabled->getSlug() . '/portraet?willkommen=1"]');
        $this->client->clickLink('Weiter');
        self::assertSelectorTextContains('.overline', 'Willkommen, Sophie');
        self::assertSelectorTextContains('main', 'Überspringen');

        $disabled = $this->fixtures->wedding();
        $this->client->request('GET', "/h/{$disabled->getSlug()}/join?k={$disabled->access()->openLinkToken()}");
        $this->client->followRedirect();
        $this->client->submitForm('Los geht’s', ['display_name[displayName]' => 'Paul']);
        self::assertResponseRedirects("/h/{$disabled->getSlug()}/codewort?weiter=start");

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
        // Oben rechts bleibt bewusst der Anfangsbuchstabe.
        self::assertSelectorTextSame('.app-header__avatar', 'S');

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

        $this->submitSelfie($wedding, overrides: ['consent' => null]);
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

    public function testGuestPicksAnOfferedStyleAndGetsAColourPortrait(): void
    {
        $wedding = $this->weddingWithPortraits();
        self::fresh($wedding)->offerPortraitStyles([PortraitStyle::Line, PortraitStyle::Watercolor]);
        self::fresh($wedding)->decidePortraitStylesBy(PortraitStyleMode::Choice);
        self::em()->flush();
        [$guest, $secret] = $this->fixtures->guest(self::fresh($wedding));
        $this->actAsGuest($guest, $secret);

        $this->client->request('GET', "/h/{$wedding->getSlug()}/portraet");
        self::assertSelectorCount(2, '.style-pick__option');
        self::assertSelectorTextContains('.style-pick', 'Aquarell');

        // Nicht angebotener Stil wird abgelehnt, bevor etwas an Gemini geht.
        $this->submitSelfie($wedding, overrides: ['style' => 'pencil']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], FakeGemini::$requests);

        $this->submitSelfie($wedding, overrides: ['style' => 'watercolor']);
        self::assertResponseRedirects("/h/{$wedding->getSlug()}");
        self::assertStringContainsString('watercolor', (string) json_encode(FakeGemini::$requests));
        $guest = self::fresh($guest);
        self::assertSame(PortraitStyle::Watercolor, $guest->getPortraitStyle());
        $size = getimagesize(self::service(PortraitStorage::class)->portraitPath($guest));
        self::assertSame([768, 768], [$size[0] ?? 0, $size[1] ?? 0]);

        $this->client->request('GET', "/h/{$wedding->getSlug()}");
        self::assertSelectorExists('img.portrait-art--color');
        $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben");
        self::assertSelectorTextContains('.challenge-item.is-done', 'Lass dich zeichnen');
    }

    public function testInRandomModeTheStyleIsASurpriseFromTheOfferedOnes(): void
    {
        $wedding = $this->weddingWithPortraits();
        self::fresh($wedding)->offerPortraitStyles([PortraitStyle::Watercolor, PortraitStyle::Comic]);
        self::em()->flush();
        self::assertSame(
            PortraitStyleMode::Random,
            self::fresh($wedding)->getPortraitStyleMode(),
            'Zufall ist der Standard.',
        );
        [$guest, $secret] = $this->fixtures->guest(self::fresh($wedding));
        $this->actAsGuest($guest, $secret);

        $this->client->request('GET', "/h/{$wedding->getSlug()}/portraet");
        self::assertSelectorNotExists('.style-pick');
        self::assertSelectorTextContains('form', 'Überraschung');

        // Ein mitgeschickter Wunsch zählt im Zufallsmodus nicht.
        $this->submitSelfie($wedding, overrides: ['style' => 'pencil']);
        self::assertResponseRedirects("/h/{$wedding->getSlug()}");
        self::assertContains(
            self::fresh($guest)->getPortraitStyle(),
            [PortraitStyle::Watercolor, PortraitStyle::Comic],
        );
    }

    public function testTheAnswerToARandomQuestionPicksTheAccessory(): void
    {
        $wedding = $this->weddingWithPortraits();
        self::assertTrue(self::fresh($wedding)->arePortraitGadgetsEnabled(), 'Accessoires sind Standard.');
        [$guest, $secret] = $this->fixtures->guest(self::fresh($wedding));
        $this->actAsGuest($guest, $secret);

        $crawler = $this->client->request('GET', "/h/{$wedding->getSlug()}/portraet");
        self::assertSelectorTextContains('.portrait-question legend', 'Kurze Frage:');
        self::assertGreaterThanOrEqual(3, $crawler->filter('.portrait-question input[name="answer"]')->count());

        // Ohne oder mit unbekannter Antwort: abgelehnt, nichts geht an Gemini.
        $this->submitSelfie($wedding, overrides: ['question' => null, 'answer' => null]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.notice--error', 'kurze Frage');
        $this->submitSelfie($wedding, overrides: ['question' => 'origin', 'answer' => 'erfunden']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], FakeGemini::$requests);

        $this->submitSelfie($wedding, overrides: ['question' => 'origin', 'answer' => 'family']);
        self::assertResponseRedirects("/h/{$wedding->getSlug()}");
        $guest = self::fresh($guest);
        self::assertSame(PortraitGadget::Crown, $guest->getPortraitGadget());
        self::assertSame('origin:family', $guest->getPortraitAnswer());
        self::assertStringContainsString(PortraitGadget::Crown->prompt(), (string) json_encode(FakeGemini::$requests));

        $this->client->request('GET', "/h/{$wedding->getSlug()}/portraet");
        self::assertSelectorTextContains('.portrait-card', 'Überraschung: mit Krone');
        // Dass die Antwort das Accessoire bestimmt, wird bewusst nicht verraten.
        self::assertSelectorTextNotContains('.portrait-card', 'Familie');
        self::assertSelectorTextNotContains('main', 'Antwort');

        // Namen aus dem Hochzeitstitel als Antworten.
        $this->submitSelfie($wedding, overrides: ['question' => 'closer', 'answer' => 'second']);
        self::assertSame(PortraitGadget::TopHat, self::fresh($guest)->getPortraitGadget());

        self::fresh($wedding)->enablePortraitGadgets(false);
        self::em()->flush();
        FakeGemini::reset();
        $this->client->request('GET', "/h/{$wedding->getSlug()}/portraet");
        self::assertSelectorNotExists('.portrait-question');
        $this->submitSelfie($wedding, overrides: ['question' => null, 'answer' => null]);
        self::assertNull(self::fresh($guest)->getPortraitGadget());
        self::assertStringNotContainsString('accessory', (string) json_encode(FakeGemini::$requests));
    }

    public function testAForgottenAnswerDoesNotUseUpTheDailyLimit(): void
    {
        $wedding = $this->weddingWithPortraits();
        [$guest, $secret] = $this->fixtures->guest(self::fresh($wedding));
        $this->actAsGuest($guest, $secret);

        for ($i = 0; $i < 5; ++$i) {
            $this->submitSelfie($wedding, overrides: ['question' => null, 'answer' => null]);
            self::assertResponseStatusCodeSame(422);
        }

        $this->submitSelfie($wedding, overrides: ['question' => 'wish', 'answer' => 'party']);
        self::assertResponseRedirects("/h/{$wedding->getSlug()}");
        self::assertSame(PortraitGadget::PartyHat, self::fresh($guest)->getPortraitGadget());
    }

    public function testCoupleChoosesTheOfferedStylesInTheSettings(): void
    {
        $wedding = $this->weddingWithPortraits();
        $user = $this->fixtures->user();
        $this->fixtures->member($user, self::fresh($wedding), [WeddingPermission::Configure]);
        $this->client->loginUser(self::fresh($user));

        $crawler = $this->client->request('GET', "/admin/h/{$wedding->getSlug()}/einstellungen");
        // Auflösung der Accessoires für das Brautpaar, mit den Namen aus dem Titel.
        self::assertSelectorTextContains('.gadget-key', 'Wen kennst du länger?');
        self::assertSelectorTextContains('.gadget-key', 'Beide gleich lang');
        self::assertSelectorTextContains('.gadget-key', 'mit Herz-Tattoo');
        self::assertSelectorTextNotContains('.gadget-key', 'brille');
        $form = $crawler->selectButton('Module speichern')->form();
        $values = $form->getPhpValues();
        $values['portrait_styles'] = ['line', 'watercolor', 'comic', 'erfunden'];
        $values['portrait_style_mode'] = 'choice';
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();
        self::assertSame(
            [PortraitStyle::Line, PortraitStyle::Watercolor, PortraitStyle::Comic],
            self::fresh($wedding)->portraitStyles(),
        );
        self::assertSame(PortraitStyleMode::Choice, self::fresh($wedding)->getPortraitStyleMode());
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

    /**
     * @param array<string, string|null> $overrides Formularfelder ersetzen; null entfernt das Feld
     */
    private function submitSelfie(
        Wedding $wedding,
        ?string $file = null,
        #[SensitiveParameter]
        ?string $token = null,
        array $overrides = [],
    ): void {
        $fields = [
            '_token' => $token ?? $this->formToken($wedding),
            'style' => 'line',
            'consent' => '1',
            'question' => 'origin',
            'answer' => 'family',
        ];
        foreach ($overrides as $name => $value) {
            if (null === $value) {
                unset($fields[$name]);
            } else {
                $fields[$name] = $value;
            }
        }

        $source = $file ?? __DIR__ . '/../../Fixtures/phase0.jpg';
        // Kopie, weil der Upload die Datei verschieben darf.
        $copy = sys_get_temp_dir() . '/selfie-' . bin2hex(random_bytes(4));
        copy($source, $copy);

        $this->client->request('POST', "/h/{$wedding->getSlug()}/portraet", $fields, ['selfie' => new UploadedFile(
            $copy,
            'selfie.jpg',
            null,
            null,
            true,
        )]);
    }
}
