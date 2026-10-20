<?php

declare(strict_types=1);

namespace App\Tests\Functional\Guest;

use App\Account\WeddingPermission;
use App\Guest\Entity\Guest;
use App\Guest\Portrait\PortraitStorage;
use App\Guestbook\Entity\GuestbookEntry;
use App\Tests\Functional\WebTestCase;
use DateTimeImmutable;
use Symfony\Component\Filesystem\Filesystem;

/** Gästewand „Wer ist alles da?“: freiwillig, gegenseitig, serverseitig geprüft. */
final class PortraitWallTest extends WebTestCase
{
    protected function tearDown(): void
    {
        $root = $_SERVER['MEDIA_ROOT'] ?? null;
        new Filesystem()->remove(is_string($root) ? $root : '/tmp/wedora-test-media');
        parent::tearDown();
    }

    public function testWallOpensOnlyForGuestsWhoShowTheirOwnPortrait(): void
    {
        $wedding = $this->fixtures->wedding();
        self::fresh($wedding)->enablePortraits(true);
        self::em()->flush();
        [$me, $secret] = $this->fixtures->guest(self::fresh($wedding), 'Ich');
        [$lea] = $this->fixtures->guest(self::fresh($wedding), 'Lea');
        [$ben] = $this->fixtures->guest(self::fresh($wedding), 'Ben');
        [$tom] = $this->fixtures->guest(self::fresh($wedding), 'Tom');
        $this->drawn($me);
        $this->drawn($lea, shared: true);
        $this->drawn($ben); // gezeichnet, aber privat
        $this->drawn($tom, shared: true);
        self::fresh($tom)->block(new DateTimeImmutable());
        self::em()->persist(new GuestbookEntry(self::fresh($lea), 'Alles Gute!'));
        self::em()->flush();
        $this->actAsGuest($me, $secret);
        $slug = $wedding->getSlug();
        $leaImage = "/h/{$slug}/portraet/von/{$lea->getId()->toRfc4122()}";

        // Gesperrt: nur die Anzahl, keine Namen, kein Bild.
        $this->client->request('GET', "/h/{$slug}/gaeste");
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.wall__locked', '1 Gast zeigt schon sein Porträt');
        self::assertSelectorNotExists('.wall');
        self::assertSelectorTextNotContains('main', 'Lea');
        $this->client->request('GET', $leaImage);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', "/h/{$slug}/gaestebuch");
        self::assertSelectorNotExists('.guestbook .avatar', 'Ohne eigene Freigabe keine kleinen Porträts.');

        // Eigenes zeigen → Wand offen.
        $crawler = $this->client->request('GET', "/h/{$slug}/gaeste");
        $this->client->submit($crawler->selectButton('Mein Porträt zeigen')->form());
        self::assertResponseRedirects("/h/{$slug}/gaeste");
        $this->client->followRedirect();
        self::assertTrue(self::fresh($me)->isPortraitShared());
        self::assertSelectorCount(2, '.wall__item');
        self::assertSelectorTextContains('.wall__item--me', 'Ich');
        self::assertSelectorExists(
            '.wall__open[data-action="portrait-zoom#open"][data-portrait-zoom-name-param="Lea"]',
            'Antippen vergrößert.',
        );
        self::assertSelectorExists('dialog.portrait-zoom:not([open])');
        self::assertSelectorTextContains('.wall', 'Lea');
        self::assertSelectorTextNotContains('.wall', 'Ben', 'Privates Porträt bleibt privat.');
        self::assertSelectorTextNotContains('.wall', 'Tom', 'Gesperrte Gäste erscheinen nicht.');
        $this->client->request('GET', $leaImage);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', "/h/{$slug}/portraet/von/{$ben->getId()->toRfc4122()}");
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', "/h/{$slug}/gaestebuch");
        self::assertSelectorExists('.guestbook .avatar');
        $this->client->request('GET', "/h/{$slug}");
        self::assertSelectorTextContains('.wall-teaser', '1 Gast zeigt sein Porträt');
        self::assertSelectorCount(1, '.wall-teaser .avatar');

        // Wieder privat → Wand wieder zu.
        $crawler = $this->client->request('GET', "/h/{$slug}/gaeste");
        $this->client->submit($crawler->selectButton('Mein Porträt nicht mehr zeigen')->form());
        self::assertFalse(self::fresh($me)->isPortraitShared());
        $this->client->request('GET', $leaImage);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeletingThePortraitEndsSharingAndStrangersSeeNothing(): void
    {
        $wedding = $this->fixtures->wedding();
        self::fresh($wedding)->enablePortraits(true);
        self::em()->flush();
        [$me, $secret] = $this->fixtures->guest(self::fresh($wedding), 'Ich');
        [$lea] = $this->fixtures->guest(self::fresh($wedding), 'Lea');
        $this->drawn($me, shared: true);
        $this->drawn($lea, shared: true);
        $other = $this->fixtures->wedding();
        self::fresh($other)->enablePortraits(true);
        self::em()->flush();
        [$stranger, $strangerSecret] = $this->fixtures->guest(self::fresh($other), 'Fremd');
        $this->drawn($stranger, shared: true);

        $this->actAsGuest($stranger, $strangerSecret);
        $this->client->request('GET', "/h/{$wedding->getSlug()}/portraet/von/{$lea->getId()->toRfc4122()}");
        self::assertFalse($this->client->getResponse()->isSuccessful(), 'Andere Hochzeit: kein Zugriff.');

        $this->actAsGuest($me, $secret);
        $crawler = $this->client->request('GET', "/h/{$wedding->getSlug()}/portraet");
        self::assertSelectorTextContains('.portrait-share', 'Auf der Gästewand');
        $this->client->submit($crawler->selectButton('Löschen')->form());
        self::assertFalse(self::fresh($me)->isPortraitShared());
        $this->client->request('GET', "/h/{$wedding->getSlug()}/portraet/von/{$lea->getId()->toRfc4122()}");
        self::assertResponseStatusCodeSame(404);

        // Brautpaar/Verwaltung: keine Porträts.
        $user = $this->fixtures->user();
        $this->fixtures->member($user, self::fresh($wedding), [WeddingPermission::Configure]);
        $this->client->getCookieJar()->clear();
        $this->client->loginUser(self::fresh($user));
        $this->client->request('GET', "/h/{$wedding->getSlug()}/portraet/von/{$lea->getId()->toRfc4122()}");
        self::assertFalse($this->client->getResponse()->isSuccessful());
    }

    public function testWithoutPortraitsTheWallDoesNotExist(): void
    {
        $wedding = $this->fixtures->wedding();
        [$me, $secret] = $this->fixtures->guest($wedding);
        $this->actAsGuest($me, $secret);

        $this->client->request('GET', "/h/{$wedding->getSlug()}/gaeste");
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', "/h/{$wedding->getSlug()}");
        self::assertSelectorNotExists('.wall-teaser');
    }

    private function drawn(Guest $guest, bool $shared = false): void
    {
        $guest = self::fresh($guest);
        $guest->portraitRequested(new DateTimeImmutable('-1 minute'));
        $guest->portraitDrawn(new DateTimeImmutable());
        $guest->sharePortrait($shared);
        self::service(PortraitStorage::class)->savePortrait($guest, $this->png());
        self::em()->flush();
    }

    private function png(): string
    {
        $path = sys_get_temp_dir() . '/portrait-' . bin2hex(random_bytes(4)) . '.png';
        copy(dirname(__DIR__, 3) . '/assets/images/app-icon-192.png', $path);

        return $path;
    }
}
