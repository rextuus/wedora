<?php

declare(strict_types=1);

namespace App\Tests\Functional\Guest;

use App\Security\Guest\GuestCookie;
use App\Tests\Functional\WebTestCase;
use DateTimeImmutable;

final class GuestAuthenticationTest extends WebTestCase
{
    public function testValidCookieGrantsAccessToOwnWedding(): void
    {
        [$guest, $secret] = $this->fixtures->guest($this->fixtures->wedding(), 'Max');
        $this->actAsGuest($guest, $secret);

        $this->client->request('GET', '/h/' . $guest->getWedding()->getSlug());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Hallo Max');
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
    }

    public function testWithoutCookieShowsInvitationHint(): void
    {
        $wedding = $this->fixtures->wedding();

        $this->client->request('GET', '/h/' . $wedding->getSlug());

        self::assertResponseStatusCodeSame(401);
        self::assertSelectorTextContains('.lead', 'QR-Code');
    }

    public function testCookieOfOneWeddingIsUselessForAnother(): void
    {
        [$guest, $secret] = $this->fixtures->guest($this->fixtures->wedding());
        $other = $this->fixtures->wedding();

        // Angreifer setzt das gültige Cookie bewusst auf den Pfad der fremden Hochzeit.
        $this->actAsGuest($guest, $secret, GuestCookie::path($other->getSlug()));
        $this->client->request('GET', '/h/' . $other->getSlug());

        self::assertResponseStatusCodeSame(401);
    }

    public function testTamperedOrMalformedCookiesAreRejectedAndCleared(): void
    {
        [$guest, $secret] = $this->fixtures->guest($this->fixtures->wedding());
        [$otherGuest] = $this->fixtures->guest($guest->getWedding(), 'Andere');
        $slug = $guest->getWedding()->getSlug();

        foreach ([
            'falsches Geheimnis' => $guest->getId()->toRfc4122() . '.' . str_repeat('A', 43),
            'fremde ID mit eigenem Geheimnis' => $otherGuest->getId()->toRfc4122() . '.' . $secret,
            'kaputt' => 'kein-gueltiges-format',
            'leer' => '.',
        ] as $label => $value) {
            $this->client->getCookieJar()->clear();
            $this->client->getCookieJar()->set(
                new \Symfony\Component\BrowserKit\Cookie(GuestCookie::NAME, $value, null, GuestCookie::path($slug)),
            );
            $this->client->request('GET', '/h/' . $slug);

            self::assertResponseStatusCodeSame(401, $label);
            $cleared = array_filter(
                $this->client->getResponse()->headers->getCookies(),
                static fn($c): bool => GuestCookie::NAME === $c->getName() && $c->isCleared(),
            );
            self::assertNotEmpty($cleared, "{$label}: ungültiges Cookie muss gelöscht werden.");
        }
    }

    public function testBlockedGuestLosesAccessImmediately(): void
    {
        [$guest, $secret] = $this->fixtures->guest($this->fixtures->wedding());
        $this->actAsGuest($guest, $secret);
        $this->client->request('GET', '/h/' . $guest->getWedding()->getSlug());
        self::assertResponseIsSuccessful();

        self::fresh($guest)->block(new DateTimeImmutable());
        $this->fixtures->flush();

        $this->client->request('GET', '/h/' . $guest->getWedding()->getSlug());
        self::assertResponseStatusCodeSame(401);
    }

    public function testRevokingInvitationLocksOutGuestsWhoJoinedThroughIt(): void
    {
        $wedding = $this->fixtures->wedding();
        [$invitation] = $this->fixtures->invitation($wedding);
        [$invited, $invitedSecret] = $this->fixtures->guest($wedding, 'Eingeladen', $invitation);
        [$openGuest, $openSecret] = $this->fixtures->guest($wedding, 'Offen');

        self::fresh($invitation)->revoke(new DateTimeImmutable());
        $this->fixtures->flush();

        $this->actAsGuest($invited, $invitedSecret);
        $this->client->request('GET', '/h/' . $wedding->getSlug());
        self::assertResponseStatusCodeSame(401);

        $this->client->getCookieJar()->clear();
        $this->actAsGuest($openGuest, $openSecret);
        $this->client->request('GET', '/h/' . $wedding->getSlug());
        self::assertResponseIsSuccessful('Gäste anderer Zugangswege sind nicht betroffen.');
    }

    public function testGuestCookieGrantsNoAccessToAdministration(): void
    {
        [$guest, $secret] = $this->fixtures->guest($this->fixtures->wedding());
        $this->actAsGuest($guest, $secret, '/');

        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/login');
    }
}
