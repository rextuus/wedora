<?php

declare(strict_types=1);

namespace App\Tests\Functional\App;

use App\Account\WeddingPermission;
use App\Tests\Functional\WebTestCase;
use App\Wedding\PhotoLook;

/** Gemeinsamer Foto-Look: in der Verwaltung wählbar, wirkt auf jeder Gastseite (nur Anzeige). */
final class PhotoLookTest extends WebTestCase
{
    public function testCoupleChoosesALookForAllPhotos(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest, $secret] = $this->fixtures->guest($wedding);
        $this->fixtures->guestMedia(self::fresh($guest));
        $this->actAsGuest($guest, $secret);
        $this->client->request('GET', "/h/{$wedding->getSlug()}/galerie");
        self::assertSelectorExists('body.look-natural', 'Standard: wie aufgenommen.');

        $user = $this->fixtures->user();
        $this->fixtures->member(
            $user,
            self::fresh($wedding),
            [WeddingPermission::Configure, WeddingPermission::ViewContent],
        );
        $this->client->getCookieJar()->clear();
        $this->client->loginUser(self::fresh($user));
        $crawler = $this->client->request('GET', "/admin/h/{$wedding->getSlug()}/einstellungen");
        self::assertSelectorCount(count(PhotoLook::cases()), '.look-pick__option img', 'Vorschau am neuesten Foto.');
        $this->client->submit($crawler->selectButton('Foto-Look speichern')->form(['photo_look' => 'warm']));
        self::assertSame(PhotoLook::Warm, self::fresh($wedding)->getPresentation()->getPhotoLook());

        $this->client->getCookieJar()->clear();
        $this->actAsGuest($guest, $secret);
        foreach (['', '/galerie', '/album'] as $page) {
            $this->client->request('GET', "/h/{$wedding->getSlug()}{$page}");
            self::assertSelectorExists('body.look-warm', "Seite „{$page}“");
        }
    }
}
