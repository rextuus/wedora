<?php

declare(strict_types=1);

namespace App\Tests\Functional\App;

use App\Account\WeddingPermission;
use App\Guest\Portrait\PortraitGadget;
use App\Guest\Portrait\PortraitStyle;
use App\Tests\Functional\WebTestCase;
use App\Tests\Support\FakeGemini;
use App\Wedding\CoupleNames;

/** Verwaltung: exakter Porträt-Prompt zum Kopieren – ohne Anfrage an Gemini. */
final class PortraitPromptTest extends WebTestCase
{
    public function testShowsTheExactPromptForStyleAndAccessory(): void
    {
        FakeGemini::reset();
        $wedding = $this->fixtures->wedding();
        self::fresh($wedding)->rename('Larissa & Tim');
        self::em()->flush();
        $user = $this->fixtures->user();
        $this->fixtures->member($user, self::fresh($wedding), [WeddingPermission::Configure]);
        $this->client->loginUser(self::fresh($user));
        $url = "/admin/h/{$wedding->getSlug()}/portraet-prompt";

        $crawler = $this->client->request('GET', "{$url}?stil=comic&accessoire=heart_tattoo");
        self::assertResponseIsSuccessful();
        $expected = PortraitStyle::Comic->prompt(PortraitGadget::HeartTattoo, CoupleNames::fromTitle('Larissa & Tim'));
        self::assertSame($expected, $crawler->filter('.prompt-box__text')->text(normalizeWhitespace: false));
        self::assertStringContainsString('"L & T"', $expected);
        self::assertSelectorExists('[data-action="clipboard#copy"]');
        self::assertSelectorTextContains('option[value="sunglasses"]', 'wird nicht mehr vergeben');
        self::assertSame([], FakeGemini::$requests, 'Nichts geht an Gemini.');

        $crawler = $this->client->request('GET', $url);
        self::assertSame(
            PortraitStyle::Line->prompt(null, CoupleNames::fromTitle('Larissa & Tim')),
            $crawler->filter('.prompt-box__text')->text(normalizeWhitespace: false),
        );

        // Nur mit Recht „Einstellungen“.
        $moderator = $this->fixtures->user();
        $this->fixtures->member($moderator, self::fresh($wedding), [WeddingPermission::Moderate]);
        $this->client->loginUser(self::fresh($moderator));
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);
    }
}
