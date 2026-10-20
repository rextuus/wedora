<?php

declare(strict_types=1);

namespace App\Tests\Functional\Guest;

use App\Guest\Entity\Guest;
use App\Security\Guest\GuestCookie;
use App\Tests\Functional\WebTestCase;
use DateTimeImmutable;
use SensitiveParameter;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class AppInstallTest extends WebTestCase
{
    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testManifestDescribesTheWeddingWithoutAnyGuestData(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest, $secret] = $this->fixtures->guest($wedding);
        $this->actAsGuest($guest, $secret);

        $this->client->request('GET', "/h/{$wedding->getSlug()}");
        self::assertSelectorExists('link[rel="manifest"]');
        self::assertSelectorExists('link[rel="apple-touch-icon"]');
        self::assertSelectorExists('[data-controller="install-hint"][hidden]');

        $this->client->request('GET', "/h/{$wedding->getSlug()}/join/manifest.webmanifest");
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/manifest+json');
        $body = (string) $this->client->getResponse()->getContent();
        $manifest = $this->json();
        self::assertSame('Anna & Lukas', $manifest['name']);
        self::assertSame("/h/{$wedding->getSlug()}/", $manifest['scope']);
        self::assertSame('standalone', $manifest['display']);
        self::assertArrayNotHasKey('start_url', $manifest, 'Browser übernehmen die aktuelle Adresse.');
        self::assertStringNotContainsString($secret, $body);

        $this->client->request('GET', '/h/gibt-es-nicht/join/manifest.webmanifest');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOneTimeLinkLogsTheHomeScreenAppInExactlyOnce(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest, $secret] = $this->fixtures->guest($wedding);
        $token = $this->issueLink($guest, $secret);

        // Frisches Gerät (Home-Bildschirm-App): keine Cookies.
        $this->client->getCookieJar()->clear();
        $url = "/h/{$wedding->getSlug()}/join/app";
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="reentry"]');

        $this->client->request('POST', $url, ['token' => $token]);
        self::assertResponseStatusCodeSame(403, 'Ohne eigenen Header (fremde Seite) kein Login.');

        $this->client->request('POST', $url, ['token' => $token], server: self::header());
        self::assertResponseIsSuccessful();
        self::assertSame("/h/{$wedding->getSlug()}", $this->json()['redirect']);
        $cookie = $this->client->getCookieJar()->get(GuestCookie::NAME, GuestCookie::path($wedding->getSlug()));
        self::assertNotNull($cookie);
        self::assertSame(
            $guest->getId()->toRfc4122() . '.' . $secret,
            $cookie->getValue(),
            'Derselbe Gast wie im Browser.',
        );
        self::assertTrue($cookie->isHttpOnly());
        $this->client->request('GET', "/h/{$wedding->getSlug()}");
        self::assertSelectorTextContains('h1', 'Hallo Sophie');

        // Zweites Einlösen (z. B. weitergeleiteter Link) scheitert.
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', $url, ['token' => $token], server: self::header());
        self::assertResponseStatusCodeSame(403);
    }

    public function testLinkNeedsAGuestAndTheOwnFrontend(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest, $secret] = $this->fixtures->guest($wedding);

        $this->client->request('POST', "/h/{$wedding->getSlug()}/app-link", server: self::header());
        self::assertFalse($this->client->getResponse()->isSuccessful(), 'Ohne Gast-Cookie kein Link.');

        $this->actAsGuest($guest, $secret);
        $this->client->request('POST', "/h/{$wedding->getSlug()}/app-link");
        self::assertResponseStatusCodeSame(403, 'Ohne eigenen Header kein Link.');
    }

    public function testExpiredForeignOrBlockedLinksAreRejected(): void
    {
        $wedding = $this->fixtures->wedding();
        $other = $this->fixtures->wedding();
        [$guest, $secret] = $this->fixtures->guest($wedding);

        $token = $this->issueLink($guest, $secret);
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', "/h/{$other->getSlug()}/join/app", ['token' => $token], server: self::header());
        self::assertResponseStatusCodeSame(403, 'Fremde Hochzeit.');

        // Gegenprobe: nach 14 Minuten noch gültig.
        $early = $this->issueLink(self::fresh($guest), $secret);
        $this->client->getCookieJar()->clear();
        Clock::set(new MockClock(new DateTimeImmutable('+14 minutes')));
        $this->client->request(
            'POST',
            "/h/{$wedding->getSlug()}/join/app",
            ['token' => $early],
            server: self::header(),
        );
        self::assertResponseIsSuccessful('Nach 14 Minuten noch gültig.');
        $this->client->getCookieJar()->clear();

        Clock::set(new MockClock(new DateTimeImmutable('+16 minutes')));
        $this->client->request(
            'POST',
            "/h/{$wedding->getSlug()}/join/app",
            ['token' => $token],
            server: self::header(),
        );
        self::assertResponseStatusCodeSame(403, 'Abgelaufen.');
        Clock::set(new NativeClock());

        $token = $this->issueLink(self::fresh($guest), $secret);
        self::fresh($guest)->block(new DateTimeImmutable());
        self::em()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->request(
            'POST',
            "/h/{$wedding->getSlug()}/join/app",
            ['token' => $token],
            server: self::header(),
        );
        self::assertResponseStatusCodeSame(403, 'Gesperrter Gast.');
    }

    private function issueLink(Guest $guest, #[SensitiveParameter] string $secret): string
    {
        $this->actAsGuest($guest, $secret);
        $this->client->request('POST', "/h/{$guest->getWedding()->getSlug()}/app-link", server: self::header());
        self::assertResponseIsSuccessful();
        // @mago-expect analysis:mixed-assignment
        $url = $this->json()['url'] ?? null;
        self::assertIsString($url);
        self::assertSame(1, preg_match('~^/h/[^/]+/join/app#t=([A-Za-z0-9_-]{43})$~', $url, $m), $url);

        return $m[1];
    }

    /**
     * @return array<string, string>
     */
    private static function header(): array
    {
        return ['HTTP_X_REQUESTED_WITH' => 'wedora'];
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        // @mago-expect analysis:mixed-assignment
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($data) ? $data : [];
    }
}
