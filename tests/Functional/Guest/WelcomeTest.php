<?php

declare(strict_types=1);

namespace App\Tests\Functional\Guest;

use App\Challenge\ChallengeTemplates;
use App\Challenge\Entity\Challenge;
use App\Tests\Functional\WebTestCase;
use App\Wedding\Entity\Wedding;
use DateTimeImmutable;

final class WelcomeTest extends WebTestCase
{
    public function testAfterTheNameNewGuestsLandOnTheFirstDrinkMission(): void
    {
        $wedding = $this->fixtures->wedding();
        $firstDrink = $this->challenge($wedding, 'first-drink');
        $this->challenge($wedding, 'last-drink', new DateTimeImmutable('today 23:00'));

        $this->client->request('GET', "/h/{$wedding->getSlug()}/join?k={$wedding->access()->openLinkToken()}");
        $this->client->followRedirect();
        $this->client->submitForm('Los geht’s', ['display_name[displayName]' => 'Paula']);
        self::assertResponseRedirects("/h/{$wedding->getSlug()}/codewort?weiter=willkommen");
        $this->client->followRedirect();
        $crawler = $this->client->clickLink('Weiter');
        self::assertSelectorTextContains('.overline', 'Schön, dass du da bist, Paula');
        self::assertSelectorTextContains('h1', 'Deine erste Aufgabe');
        self::assertSelectorExists('.drink-explainer');
        self::assertSelectorTextContains('.drink-example', 'ich bin auch dabei');
        self::assertSelectorExists('.drink-example video[src*="uwe"][controls]');
        self::assertSelectorCount(2, '.drink-explainer a.drink-explainer__step', 'Beide Drink-Aufgaben verlinkt.');
        self::assertSelectorTextContains('main', 'ab 23:00 Uhr');
        $cta = (string) $crawler->filter('.action-primary')->attr('href');
        self::assertStringContainsString('aufgabe=' . $firstDrink->getId()->toRfc4122(), $cta);
        self::assertStringContainsString('kamera=selfie', $cta);

        // Upload-Seite öffnet für das First-Drink-Video die Frontkamera und nimmt nur Videos.
        $this->client->request('GET', $cta);
        self::assertSelectorExists('input[type="file"][capture="user"][accept="video/*"]');
    }

    public function testWithoutFirstDrinkTheWelcomeStepIsSkipped(): void
    {
        $wedding = $this->fixtures->wedding();
        $this->challenge($wedding, 'dancefloor');

        $this->client->request('GET', "/h/{$wedding->getSlug()}/join?k={$wedding->access()->openLinkToken()}");
        $this->client->followRedirect();
        $this->client->submitForm('Los geht’s', ['display_name[displayName]' => 'Paula']);
        self::assertResponseRedirects("/h/{$wedding->getSlug()}/codewort?weiter=start");

        $this->client->request('GET', "/h/{$wedding->getSlug()}/willkommen");
        self::assertResponseRedirects("/h/{$wedding->getSlug()}");
    }

    private function challenge(Wedding $wedding, string $template, ?DateTimeImmutable $startsAt = null): Challenge
    {
        $t = ChallengeTemplates::all()[$template];
        $challenge = new Challenge(self::fresh($wedding), $t['title']);
        $challenge->describe($t['description']);
        $challenge->setMediaType($t['type']);
        $challenge->schedule($startsAt, null);
        self::em()->persist($challenge);
        self::em()->flush();

        return $challenge;
    }
}
