<?php

declare(strict_types=1);

namespace App\Tests\Functional\Guest;

use App\Security\Guest\GuestCookie;
use App\Tests\Functional\WebTestCase;

/**
 * Live läuft Wedora hinter Caddy und einem Reverse-Proxy (VPS über WireGuard), der HTTPS macht.
 * Symfony glaubt X-Forwarded-* nur dem eigenen Caddy (privates Docker-Netz), sonst niemandem.
 */
final class ReverseProxyTest extends WebTestCase
{
    public function testBehindTheProxyLinksAndCookiesAreHttps(): void
    {
        $wedding = $this->fixtures->wedding();
        // Wie ein echter Browser hinter dem VPS-Caddy: Anfrage kommt vom eigenen Caddy (privates Netz),
        // mit X-Forwarded-Proto https; Browser schicken bei Formularen Sec-Fetch-Site mit.
        $viaProxy = [
            'REMOTE_ADDR' => '172.20.0.5',
            'HTTP_HOST' => 'wedora.example.de',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ];
        $this->client->setServerParameters($viaProxy);

        $this->client->request('GET', "/h/{$wedding->getSlug()}/join?k={$wedding->access()->openLinkToken()}");
        self::assertResponseRedirects("https://wedora.example.de/h/{$wedding->getSlug()}/join/name");
        $this->client->followRedirect();
        $this->client->submitForm('Los geht’s', ['display_name[displayName]' => 'Sophie']);

        // Der Test-Browser selbst spricht kein HTTPS – entscheidend ist, was der Server setzt.
        $cookies = $this->client->getResponse()->headers->getCookies();
        $guestCookie =
            array_values(array_filter($cookies, static fn($c): bool => GuestCookie::NAME === $c->getName()))[0] ?? null;
        self::assertNotNull($guestCookie);
        self::assertTrue($guestCookie->isSecure(), 'Über HTTPS: Gast-Cookie nur noch verschlüsselt.');
        self::assertTrue($guestCookie->isHttpOnly());
    }

    public function testForeignSendersCannotPretendHttps(): void
    {
        $wedding = $this->fixtures->wedding();
        $this->client->setServerParameters([
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_HOST' => 'wedora.example.de',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $this->client->request('GET', "/h/{$wedding->getSlug()}/join?k={$wedding->access()->openLinkToken()}");
        self::assertResponseRedirects();
        self::assertStringStartsNotWith('https://', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
