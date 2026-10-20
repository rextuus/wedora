<?php

declare(strict_types=1);

namespace App\Tests\Functional\App;

use App\Account\WeddingPermission;
use App\Challenge\ChallengeMediaType;
use App\Challenge\Entity\Challenge;
use App\Media\MediaType;
use App\Media\Visibility;
use App\Tests\Functional\WebTestCase;
use App\Wedding\CoverMode;
use App\Wedding\Host\WeddingPicture;
use App\Wedding\Host\WeddingPictureStorage;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\Filesystem\Filesystem;

/** Titelbild der Startseite: neuestes Foto, festes Bild (z. B. vom Brautpaar) oder keins. */
final class CoverTest extends WebTestCase
{
    protected function tearDown(): void
    {
        $root = $_SERVER['MEDIA_ROOT'] ?? null;
        new Filesystem()->remove(is_string($root) ? $root : '/tmp/wedora-test-media');
        parent::tearDown();
    }

    public function testCoupleChoosesTheCoverAndGuestsSeeIt(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest, $secret] = $this->fixtures->guest(self::fresh($wedding), 'Sophie');
        $this->fixtures->guestMedia(self::fresh($guest));
        $slug = $wedding->getSlug();
        $coverUrl = "/h/{$slug}/bild/titelbild.jpg";

        // Standard: Mosaik der neuesten Beiträge, kein einzelnes Titelbild.
        $this->actAsGuest($guest, $secret);
        $this->client->request('GET', "/h/{$slug}");
        self::assertSelectorExists('.home-mosaic .magazine .tile');
        self::assertSelectorNotExists('img.hero__image');

        // Festes Bild ohne Foto geht nicht.
        $user = $this->fixtures->user();
        $this->fixtures->member($user, self::fresh($wedding), [WeddingPermission::Configure]);
        $this->client->getCookieJar()->clear();
        $this->client->loginUser(self::fresh($user));
        $crawler = $this->client->request('GET', "/admin/h/{$slug}/einstellungen");
        $this->client->submit($crawler->selectButton('Titelbild speichern')->form(['cover_mode' => 'fixed']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'bitte ein Foto hochladen');
        self::assertSame(CoverMode::Latest, self::fresh($wedding)->getPresentation()->getCoverMode());

        // Mit Foto: festes Titelbild.
        $crawler = $this->client->request('GET', "/admin/h/{$slug}/einstellungen");
        $form = $crawler->selectButton('Titelbild speichern')->form(['cover_mode' => 'fixed']);
        $copy = sys_get_temp_dir() . '/cover-' . bin2hex(random_bytes(4)) . '.jpg';
        copy(__DIR__ . '/../../Fixtures/phase0.jpg', $copy);
        // @mago-expect analysis:mixed-assignment
        $field = $form['cover_photo'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($copy);
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertTrue(self::fresh($wedding)->getPresentation()->showsFixedCover());
        $path = self::service(WeddingPictureStorage::class)->path(self::fresh($wedding), WeddingPicture::Cover);
        self::assertFileExists($path);
        $this->client->request('GET', "/admin/h/{$slug}/einstellungen");
        self::assertSelectorExists('img[src^="/admin/h/' . $slug . '/einstellungen/bild/titelbild.jpg"]');
        $this->client->request('GET', "/admin/h/{$slug}/einstellungen/bild/titelbild.jpg");
        self::assertResponseIsSuccessful('Die Verwaltung sieht die Vorschau.');

        $this->client->getCookieJar()->clear();
        $this->actAsGuest($guest, $secret);
        $this->client->request('GET', "/h/{$slug}");
        self::assertSelectorExists('img.hero__image[src^="' . $coverUrl . '"]');
        self::assertSelectorNotExists('.home-mosaic', 'Mit festem Titelbild kein Mosaik …');
        self::assertSelectorExists('[aria-labelledby="fresh-title"] .tile', '… dafür der Streifen „Frisch von der Feier“.');
        $this->client->request('GET', $coverUrl);
        self::assertResponseIsSuccessful();

        // Gäste anderer Hochzeiten sehen es nicht.
        $other = $this->fixtures->wedding();
        [$stranger, $strangerSecret] = $this->fixtures->guest(self::fresh($other));
        $this->client->getCookieJar()->clear();
        $this->actAsGuest($stranger, $strangerSecret);
        $this->client->request('GET', $coverUrl);
        self::assertFalse($this->client->getResponse()->isSuccessful());

        // Kein Bild.
        self::fresh($wedding)->getPresentation()->chooseCover(CoverMode::None);
        self::em()->flush();
        $this->client->getCookieJar()->clear();
        $this->actAsGuest($guest, $secret);
        $this->client->request('GET', "/h/{$slug}");
        self::assertSelectorNotExists('img.hero__image');
        self::assertSelectorNotExists('.home-mosaic');
        self::assertSelectorExists('.hero--plain');
    }

    public function testMosaicShowsOneMagazineBlockWithoutLockedDrinkVideosAndLinksToTheGallery(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest, $secret] = $this->fixtures->guest(self::fresh($wedding), 'Sophie');
        [$ben] = $this->fixtures->guest(self::fresh($wedding), 'Ben');
        $first = new Challenge(self::fresh($wedding), 'First Drink');
        $first->setMediaType(ChallengeMediaType::Video);
        self::em()->persist($first);
        self::em()->persist(new Challenge(self::fresh($wedding), 'Last Drink'));
        self::em()->flush();
        for ($i = 0; $i < 6; ++$i) {
            $this->fixtures->guestMedia(self::fresh($ben));
        }

        // Das neueste ist ein fremdes First-Drink-Video – für Sophie gesperrt (eigenes fehlt).
        $drink = $this->fixtures->guestMedia(self::fresh($ben), Visibility::AllGuests, MediaType::Video);
        self::fresh($drink)->assignChallenge(self::fresh($first));

        self::em()->flush();
        $this->actAsGuest($guest, $secret);

        $this->client->request('GET', "/h/{$wedding->getSlug()}");
        self::assertSelectorCount(5, '.home-mosaic .magazine .tile', 'Sechs neueste, davon ein gesperrtes Drink-Video weg.');
        self::assertSelectorNotExists('.home-mosaic .tile--locked');
        self::assertSelectorExists('.home-mosaic a.button[href="/h/' . $wedding->getSlug() . '/galerie"]');
        self::assertSelectorTextContains('.home-mosaic', 'Zur Galerie');
        self::assertSelectorNotExists('[aria-labelledby="fresh-title"]', 'Kein doppelter Streifen.');
    }
}
