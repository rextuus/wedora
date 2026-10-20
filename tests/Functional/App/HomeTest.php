<?php

declare(strict_types=1);

namespace App\Tests\Functional\App;

use App\Account\WeddingPermission;
use App\Challenge\Entity\Challenge;
use App\Media\MediaType;
use App\Media\Visibility;
use App\Tests\Functional\WebTestCase;
use App\Wedding\Entity\Wedding;
use App\Wedding\NudgeMode;
use DateTimeImmutable;

/** Startseite der Gäste: Teilnehmerzahlen, lebendige Fotos, Figur „Zu viel am Handy?“. */
final class HomeTest extends WebTestCase
{
    public function testHomeShowsHowManyGuestsJoinedTheChallenges(): void
    {
        $wedding = $this->fixtures->wedding();
        $active = $this->challenge($wedding, 'Brautstrauß', 5);
        $next = $this->challenge($wedding, 'Tortenanschnitt', 5);
        [$me, $secret] = $this->fixtures->guest($wedding, 'Ich');
        $this->actAsGuest($me, $secret);

        $this->client->request('GET', "/h/{$wedding->getSlug()}");
        self::assertSelectorTextContains('.mission .participation', 'Noch niemand dabei');

        foreach (['Ben', 'Lea'] as $name) {
            [$guest] = $this->fixtures->guest(self::fresh($wedding), $name);
            self::fresh($this->fixtures->guestMedia(self::fresh($guest)))->assignChallenge(self::fresh($active));
        }
        [$mia] = $this->fixtures->guest(self::fresh($wedding), 'Mia');
        self::fresh($this->fixtures->guestMedia(self::fresh($mia)))->assignChallenge(self::fresh($next));
        self::em()->flush();

        $this->client->request('GET', "/h/{$wedding->getSlug()}");
        self::assertSelectorTextContains('.mission__title', 'Brautstrauß');
        self::assertSelectorTextContains('.mission .participation', '2 Gäste haben schon mitgemacht');
        self::assertSelectorTextContains('.mission-list', 'Tortenanschnitt');
        self::assertSelectorTextContains('.mission-list', '1 dabei');
    }

    public function testSomePhotosDriftSlowlyButNeverVideos(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest, $secret] = $this->fixtures->guest($wedding);
        for ($i = 0; $i < 12; ++$i) {
            $this->fixtures->guestMedia(
                self::fresh($guest),
                Visibility::AllGuests,
                0 === ($i % 3) ? MediaType::Video : MediaType::Photo,
            );
        }

        $this->actAsGuest($guest, $secret);
        $crawler = $this->client->request('GET', "/h/{$wedding->getSlug()}/galerie");
        $tiles = $crawler->filter('.magazine a.tile');
        self::assertGreaterThan(0, $tiles->count());
        foreach ($tiles as $tile) {
            self::assertInstanceOf(\DOMElement::class, $tile);
            $id = basename($tile->getAttribute('href'));
            $isVideo = $tile->hasAttribute('data-video');
            $expected = !$isVideo && in_array(substr($id, -1), ['1', '5', '9', 'd'], true);
            self::assertSame($expected, str_contains($tile->getAttribute('class'), 'tile--drift'), "Kachel {$id}");
        }
    }

    public function testTheLittlePhoneFigureIsHiddenUntilItsTimeHasCome(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest, $secret] = $this->fixtures->guest($wedding);
        $this->actAsGuest($guest, $secret);

        $this->client->request('GET', "/h/{$wedding->getSlug()}");
        self::assertSelectorExists(
            '.nudge__trigger[hidden][data-action="nudge#open"] .ornament--zombie',
            'Am Anfang unsichtbar.',
        );
        self::assertSelectorExists('dialog.nudge__dialog:not([open])', 'Erst auf Tippen sichtbar.');
        self::assertSelectorTextContains('.nudge__dialog', 'Ja, die Seite hier ist mega cool, ich weiß.');
        self::assertSelectorTextContains('.nudge__dialog', 'auch später noch hochladen');
    }

    public function testCoupleLetsTheReminderAppearByTimeOrByManyPosts(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest, $secret] = $this->fixtures->guest($wedding);
        $user = $this->fixtures->user();
        $this->fixtures->member($user, self::fresh($wedding), [WeddingPermission::Configure]);
        $slug = $wedding->getSlug();

        $this->actAsGuest($guest, $secret);
        $this->client->request('GET', "/h/{$slug}/galerie");
        self::assertSelectorExists(
            'main[data-nudge-mode-value="time"][data-nudge-threshold-value="20"] .nudge__trigger[hidden] ~ dialog.nudge__dialog',
            'Figur (versteckt) und Popup auf jeder Seite, Standard: nach 20 Minuten.',
        );

        // Nach vielen Beiträgen: Schwelle aus der Verwaltung, gezählt wird die letzte Stunde.
        $this->client->getCookieJar()->clear();
        $this->client->loginUser(self::fresh($user));
        $crawler = $this->client->request('GET', "/admin/h/{$slug}/einstellungen");
        $this->client->submit($crawler
            ->selectButton('Handy-Hinweis speichern')
            ->form([
                'nudge_mode' => 'activity',
                'nudge_threshold' => '2',
            ]));
        self::assertSame(NudgeMode::Activity, self::fresh($wedding)->getPresentation()->getNudgeMode());
        $old = $this->fixtures->guestMedia(self::fresh($guest));
        self::em()
            ->getConnection()
            ->executeStatement('UPDATE media SET created_at = ? WHERE id = ?', [
                new DateTimeImmutable('-2 hours')->format('Y-m-d H:i:s'),
                $old->getId()->toBinary(),
            ]);
        $this->fixtures->guestMedia(self::fresh($guest));
        $this->fixtures->guestMedia(self::fresh($guest));

        $this->client->getCookieJar()->clear();
        $this->actAsGuest($guest, $secret);
        $this->client->request('GET', "/h/{$slug}");
        self::assertSelectorExists(
            'main[data-nudge-mode-value="activity"][data-nudge-threshold-value="2"][data-nudge-recent-value="2"]',
        );

        // Nach Zeit: ohne Zahl gilt der Vorschlag (20 Minuten); gemessen wird nur im Browser.
        self::fresh($wedding)->getPresentation()->configureNudge(NudgeMode::Time, 0);
        self::em()->flush();
        $this->client->request('GET', "/h/{$slug}");
        self::assertSelectorExists(
            'main[data-nudge-mode-value="time"][data-nudge-threshold-value="20"][data-nudge-recent-value="0"]',
        );

        // Gar nicht: keine Schwelle, die Figur taucht nie auf.
        self::fresh($wedding)->getPresentation()->configureNudge(NudgeMode::Off, 5);
        self::em()->flush();
        $this->client->request('GET', "/h/{$slug}");
        self::assertSelectorExists('main[data-nudge-mode-value="off"][data-nudge-threshold-value="0"]');
    }

    public function testAddToHomeScreenHintComesLast(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest, $secret] = $this->fixtures->guest($wedding);
        $this->actAsGuest($guest, $secret);

        $this->client->request('GET', "/h/{$wedding->getSlug()}");
        $html = (string) $this->client->getResponse()->getContent();
        $hint = strpos($html, 'class="install-hint"');
        self::assertNotFalse($hint);
        self::assertGreaterThan(strpos($html, 'class="privacy-note"'), $hint, 'Ganz unten, nach allem anderen.');
        self::assertGreaterThan(strpos($html, 'class="actions"'), $hint);
    }

    private function challenge(Wedding $wedding, string $title, int $points): Challenge
    {
        $challenge = new Challenge(self::fresh($wedding), $title);
        $challenge->setPoints($points);
        self::em()->persist($challenge);
        self::em()->flush();

        return $challenge;
    }
}
