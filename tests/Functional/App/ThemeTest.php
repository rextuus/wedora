<?php

declare(strict_types=1);

namespace App\Tests\Functional\App;

use App\Account\WeddingPermission;
use App\Tests\Functional\WebTestCase;
use App\Wedding\Theme;

/** Stil der Seite: in der Verwaltung wählbar, gilt auf Gastseiten, beim Einstieg und für die App auf dem Startbildschirm. */
final class ThemeTest extends WebTestCase
{
    public function testCoupleChoosesTheHandwrittenStyle(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest, $secret] = $this->fixtures->guest($wedding);
        $this->actAsGuest($guest, $secret);
        $this->client->request('GET', "/h/{$wedding->getSlug()}");
        self::assertSelectorExists('body.theme-botanical', 'Standard: botanisch.');
        self::assertSelectorNotExists('#pen-wobble');

        $user = $this->fixtures->user();
        $this->fixtures->member($user, self::fresh($wedding), [WeddingPermission::Configure]);
        $this->client->getCookieJar()->clear();
        $this->client->loginUser(self::fresh($user));
        $crawler = $this->client->request('GET', "/admin/h/{$wedding->getSlug()}/einstellungen");
        self::assertSelectorCount(count(Theme::cases()), '.theme-pick__option', 'Jeder Stil mit Vorschau.');
        $this->client->submit($crawler->selectButton('Stil speichern')->form(['theme' => 'handwritten']));
        self::assertResponseRedirects();
        self::assertSame(Theme::Handwritten, self::fresh($wedding)->getPresentation()->getTheme());

        $this->client->getCookieJar()->clear();
        $this->actAsGuest($guest, $secret);
        foreach (['', '/galerie', '/album'] as $page) {
            $this->client->request('GET', "/h/{$wedding->getSlug()}{$page}");
            self::assertSelectorExists('body.theme-handwritten', "Seite „{$page}“");
            self::assertSelectorExists('#pen-wobble', 'Filter für gezeichnete Linien.');
            self::assertSelectorExists('meta[name="theme-color"][content="#f0e6e2"]');
        }

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', "/h/{$wedding->getSlug()}/join?k={$wedding->access()->openLinkToken()}");
        $this->client->followRedirect();
        self::assertSelectorExists('body.theme-handwritten', 'Schon der Einstieg im Stil der Hochzeit.');

        $this->client->request('GET', "/h/{$wedding->getSlug()}/join/manifest.webmanifest");
        /** @var array{theme_color: string} $manifest */
        $manifest = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('#f0e6e2', $manifest['theme_color']);
    }
}
