<?php

declare(strict_types=1);

namespace App\Tests\Functional\App;

use App\Account\WeddingPermission;
use App\Challenge\ChallengeMediaType;
use App\Challenge\Entity\Challenge;
use App\Gallery\GalleryQuery;
use App\Guestbook\Entity\GuestbookEntry;
use App\Guestbook\GuestbookFeed;
use App\Media\Entity\Media;
use App\Media\MediaType;
use App\Media\Repository\MediaRepository;
use App\Media\Visibility;
use App\Moderation\ModerationQueue;
use App\Security\MediaAccessPolicy;
use App\Security\Viewer;
use App\Social\Repository\MediaLikeRepository;
use App\Tests\Functional\WebTestCase;
use App\Wedding\Entity\Wedding;
use App\Wedding\ModerationMode;
use App\Wedding\ModuleType;
use App\Wedding\NudgeMode;
use DateTimeImmutable;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

final class GuestAppTest extends WebTestCase
{
    protected function tearDown(): void
    {
        $root = $_SERVER['MEDIA_ROOT'] ?? null;
        new Filesystem()->remove(is_string($root) ? $root : '/tmp/wedora-test-media');
        parent::tearDown();
    }

    public function testGalleryListsOnlySharedItemsAndNeverMoreThanPolicyAllows(): void
    {
        $wedding = $this->fixtures->wedding();
        [$me, $secret] = $this->fixtures->guest($wedding, 'Ich');
        [$other] = $this->fixtures->guest($wedding, 'Andere');
        [$foreign] = $this->fixtures->guest($this->fixtures->wedding(), 'Fremd');

        $shared = $this->fixtures->guestMedia($other);
        $mineShared = $this->fixtures->guestMedia($me);
        $couple = $this->fixtures->guestMedia($other, Visibility::Couple);
        $private = $this->fixtures->guestMedia($other, Visibility::UploaderOnly);
        $processing = $this->fixtures->guestMedia($other, ready: false);
        $pending = $this->fixtures->guestMedia($other);
        self::fresh($pending)->reject(new DateTimeImmutable());
        $deleted = $this->fixtures->guestMedia($other);
        self::fresh($deleted)->markDeleted(new DateTimeImmutable());
        $this->fixtures->guestMedia($foreign);
        $this->fixtures->flush();

        $listed = self::service(GalleryQuery::class)->shared(self::fresh($me));
        $ids = array_map(static fn(Media $m): string => $m->getId()->toRfc4122(), $listed);

        self::assertEqualsCanonicalizing([$shared->getId()->toRfc4122(), $mineShared->getId()->toRfc4122()], $ids);

        // Konsistenz: jede Liste ist eine Teilmenge dessen, was die Rechteprüfung erlaubt.
        $viewer = Viewer::guest($me->getId(), $wedding->getId());
        $policy = new MediaAccessPolicy();
        foreach ([...$listed, ...self::service(GalleryQuery::class)->mine(self::fresh($me))] as $media) {
            self::assertTrue($policy->canView($viewer, $media), $media->getId()->toRfc4122());
        }

        $this->actAsGuest($me, $secret);
        $this->client->request('GET', "/h/{$wedding->getSlug()}/galerie");
        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->client->getCrawler()->filter('.magazine .tile'));

        foreach ([$couple, $private, $processing, $pending, $deleted] as $hidden) {
            $this->client->request('GET', "/h/{$wedding->getSlug()}/m/{$hidden->getId()->toRfc4122()}");
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testGalleryFilterAndPagination(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest, $secret] = $this->fixtures->guest($wedding);
        for ($i = 0; $i < (GalleryQuery::PAGE_SIZE + 6); ++$i) {
            $this->fixtures->guestMedia($guest);
        }
        $this->fixtures->guestMedia($guest, type: MediaType::Video);
        $this->actAsGuest($guest, $secret);

        $this->client->request('GET', "/h/{$wedding->getSlug()}/galerie?filter=videos");
        self::assertCount(1, $this->client->getCrawler()->filter('.magazine .tile'));

        $crawler = $this->client->request('GET', "/h/{$wedding->getSlug()}/galerie");
        self::assertCount(GalleryQuery::PAGE_SIZE, $crawler->filter('.magazine .tile'));
        $more = $crawler->filter('turbo-frame[loading="lazy"]')->attr('src');
        self::assertNotNull($more);

        $page = $this->client->request('GET', $more);
        self::assertCount(7, $page->filter('.magazine .tile'), 'restliche 6 Fotos + 1 Video');
    }

    public function testLikeToggleRequiresTokenAndVisibility(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest, $secret] = $this->fixtures->guest($wedding);
        [$other] = $this->fixtures->guest($wedding, 'Andere');
        $media = $this->fixtures->guestMedia($other);
        $hidden = $this->fixtures->guestMedia($other, Visibility::UploaderOnly);
        $this->actAsGuest($guest, $secret);
        $token = $this->guestToken($wedding);
        $url = "/h/{$wedding->getSlug()}/m/{$media->getId()->toRfc4122()}/like";

        // @mago-expect lint:no-literal-password
        $this->client->request('POST', $url, ['_token' => 'falsch']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', $url, ['_token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.like-button', '1');
        self::assertSame(1, self::service(MediaLikeRepository::class)->count(['media' => self::fresh($media)]));

        $this->client->request('POST', $url, ['_token' => $token]);
        self::assertSelectorTextContains('.like-button', '0');

        $this->client->request('POST', "/h/{$wedding->getSlug()}/m/{$hidden->getId()->toRfc4122()}/like", [
            '_token' => $token,
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testReportAppearsInModerationButOwnMediaCannotBeReported(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest, $secret] = $this->fixtures->guest($wedding);
        [$other] = $this->fixtures->guest($wedding, 'Andere');
        $media = $this->fixtures->guestMedia($other);
        $own = $this->fixtures->guestMedia($guest);
        $this->actAsGuest($guest, $secret);
        $token = $this->guestToken($wedding);

        foreach ([$media, $media, $own] as $item) {
            $this->client->request('POST', "/h/{$wedding->getSlug()}/m/{$item->getId()->toRfc4122()}/melden", [
                '_token' => $token,
                'reason' => 'privacy',
                'note' => 'Bitte entfernen',
            ]);
            self::assertResponseRedirects();
        }

        $reports = self::service(ModerationQueue::class)->openReports(self::fresh($wedding));
        self::assertCount(1, $reports, 'doppelte Meldung zählt einmal, eigene gar nicht');
        self::assertSame('privacy', $reports[0]->getReason()->value);
        self::assertSame('Bitte entfernen', $reports[0]->getNote());
    }

    public function testAlbumShowsOwnItemsWithStatus(): void
    {
        $wedding = $this->fixtures->wedding(ModerationMode::Approval);
        [$guest, $secret] = $this->fixtures->guest($wedding);
        $this->fixtures->guestMedia($guest);
        $this->fixtures->guestMedia($guest, Visibility::UploaderOnly);
        $this->actAsGuest($guest, $secret);

        $crawler = $this->client->request('GET', "/h/{$wedding->getSlug()}/album");

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.grid .tile'));
        $flags = $crawler->filter('.tile__flag')->each(static fn($node): string => trim($node->text()));
        self::assertEqualsCanonicalizing(['Wartet', 'Privat'], $flags, 'private Beiträge brauchen keine Freigabe');
    }

    public function testGuestbookVisibilityModerationAndDeletion(): void
    {
        $wedding = $this->fixtures->wedding(ModerationMode::Approval);
        [$author, $authorSecret] = $this->fixtures->guest($wedding, 'Autorin');
        [$reader, $readerSecret] = $this->fixtures->guest($wedding, 'Leser');
        $this->actAsGuest($author, $authorSecret);
        $token = $this->guestToken($wedding);

        $this->client->request('POST', "/h/{$wedding->getSlug()}/gaestebuch", [
            '_token' => $token,
            'message' => 'Alles Liebe!',
            'visibility' => 'all_guests',
        ]);
        self::assertResponseRedirects();
        $this->client->request('POST', "/h/{$wedding->getSlug()}/gaestebuch", [
            '_token' => $token,
            'message' => 'Nur für euch',
            'visibility' => 'couple',
        ]);
        $this->client->request('POST', "/h/{$wedding->getSlug()}/gaestebuch", [
            '_token' => $token,
            'message' => str_repeat('x', 2001),
        ]);

        $feed = self::service(GuestbookFeed::class);
        self::assertCount(2, $feed->forGuest(self::fresh($author)), 'Autorin sieht beide eigenen (wartend)');
        self::assertCount(0, $feed->forGuest(self::fresh($reader)), 'Leser sieht nichts Unveröffentlichtes');

        $entries = self::em()
            ->getRepository(GuestbookEntry::class)
            ->findBy(['author' => self::fresh($author)]);
        foreach ($entries as $entry) {
            $entry->approve(new DateTimeImmutable());
        }
        self::em()->flush();
        $visible = $feed->forGuest(self::fresh($reader));
        self::assertCount(1, $visible, '„Nur Brautpaar“ bleibt für Gäste verborgen');
        self::assertInstanceOf(GuestbookEntry::class, $visible[0]);
        self::assertSame('Alles Liebe!', $visible[0]->getMessage());

        $this->client->getCookieJar()->clear();
        $this->actAsGuest($reader, $readerSecret);
        $this->client->request(
            'POST',
            "/h/{$wedding->getSlug()}/gaestebuch/{$visible[0]->getId()->toRfc4122()}/loeschen",
            ['_token' => $this->guestToken($wedding)],
        );
        self::assertResponseStatusCodeSame(403, 'fremde Einträge nicht löschbar');
    }

    public function testChallengeUploadCompletesTaskAndRespectsWindowAndType(): void
    {
        $wedding = $this->fixtures->wedding();
        $wedding->enableChallengePoints(true);
        $open = $this->challenge($wedding, 'First Drink', 10);
        $later = $this->challenge($wedding, 'Last Drink', 10);
        self::fresh($later)->schedule(new DateTimeImmutable('+2 hours'), null);
        $videoOnly = $this->challenge($wedding, 'Tanzfläche', 15);
        self::fresh($videoOnly)->setMediaType(ChallengeMediaType::Video);
        $this->fixtures->flush();
        [$guest, $secret] = $this->fixtures->guest($wedding, 'Sophie');
        $this->actAsGuest($guest, $secret);
        $photo = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/phase0.jpg');

        $crawler = $this->client->request('GET', "/h/{$wedding->getSlug()}");
        self::assertStringContainsString('First Drink', $crawler->filter('.mission')->text());

        $mediaId = $this->uploadPhoto($wedding, $photo, $open->getId()->toRfc4122());
        $media = self::service(MediaRepository::class)->find(Uuid::fromString($mediaId));
        self::assertNotNull($media);
        self::assertSame(ModuleType::Challenges, $media->getModule());
        self::assertSame('First Drink', $media->getChallenge()?->getTitle());

        $this->apiStart($wedding, strlen($photo), $later->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(403, 'noch nicht gestartet');
        $this->apiStart($wedding, strlen($photo), $videoOnly->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(422, 'falscher Medientyp');

        $crawler = $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben/{$open->getId()->toRfc4122()}");
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'First Drink');
        self::assertSelectorTextContains('.mission', 'Erledigt');
        self::assertCount(1, $crawler->filter('.grid .tile'), 'eigener Beitrag zur Aufgabe');
        self::assertLessThan(
            200,
            substr_count((string) $this->client->getResponse()->getContent(), "\n") / 10,
            'Seite in normaler Größe',
        );

        $crawler = $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben");
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1 von 3 erledigt', $crawler->filter('.section-header')->text());
        self::assertStringContainsString('Sophie', $crawler->filter('.ranking')->text());
        self::assertStringContainsString('10', $crawler->filter('.ranking')->text());
    }

    public function testFirstDrinkIsReplacedInsteadOfCollectingMore(): void
    {
        $wedding = $this->fixtures->wedding();
        $first = $this->challenge($wedding, 'First Drink', 10);
        $other = $this->challenge($wedding, 'Brautstrauß', 10);
        [$guest, $secret] = $this->fixtures->guest($wedding, 'Sophie');
        $this->actAsGuest($guest, $secret);
        $photo = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/phase0.jpg');
        $repo = self::service(MediaRepository::class);

        $old = $this->uploadPhoto($wedding, $photo, $first->getId()->toRfc4122());
        $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben/{$first->getId()->toRfc4122()}");
        self::assertSelectorTextContains('.mission .button', 'Video ersetzen');
        self::assertSelectorTextContains('.mission', 'Ein neues ersetzt dein bisheriges');
        $this->client->request('GET', "/h/{$wedding->getSlug()}/upload?aufgabe={$first->getId()->toRfc4122()}");
        self::assertSelectorExists('input[type="file"]:not([multiple])');

        $new = $this->uploadPhoto($wedding, $photo, $first->getId()->toRfc4122());
        self::assertTrue($repo->find(Uuid::fromString($old))?->isDeleted(), 'Das alte Video ist gelöscht.');
        self::assertFalse($repo->find(Uuid::fromString($new))?->isDeleted());
        $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben/{$first->getId()->toRfc4122()}");
        self::assertSelectorCount(1, '[aria-labelledby="mine-title"] .tile');

        // Andere Aufgaben sammeln weiter.
        $a = $this->uploadPhoto($wedding, $photo, $other->getId()->toRfc4122());
        $this->uploadPhoto($wedding, $photo, $other->getId()->toRfc4122());
        self::assertFalse($repo->find(Uuid::fromString($a))?->isDeleted());
        $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben");
        self::assertSelectorTextContains('.challenge-item:nth-child(1) .button', 'Video ersetzen');
        self::assertSelectorTextContains('.challenge-item:nth-child(2) .button', 'Noch einen Beitrag');
    }

    public function testDisabledModulesAreHiddenAndUnreachable(): void
    {
        $wedding = $this->fixtures->wedding();
        $wedding->module(ModuleType::Challenges)->setEnabled(false);
        $wedding->module(ModuleType::Guestbook)->setEnabled(false);
        $this->fixtures->flush();
        [$guest, $secret] = $this->fixtures->guest($wedding);
        $this->actAsGuest($guest, $secret);

        $crawler = $this->client->request('GET', "/h/{$wedding->getSlug()}");
        self::assertStringNotContainsString('Aufgaben', $crawler->filter('.bottom-nav')->text());
        self::assertStringNotContainsString('Gästebuch', $crawler->filter('.bottom-nav')->text());

        foreach (['aufgaben', 'gaestebuch'] as $page) {
            $this->client->request('GET', "/h/{$wedding->getSlug()}/{$page}");
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testOnlyUploaderCanEditCaption(): void
    {
        $wedding = $this->fixtures->wedding();
        [$owner, $ownerSecret] = $this->fixtures->guest($wedding, 'Owner');
        [$other, $otherSecret] = $this->fixtures->guest($wedding, 'Andere');
        $media = $this->fixtures->guestMedia($owner);
        $url = "/h/{$wedding->getSlug()}/m/{$media->getId()->toRfc4122()}/beschriftung";

        $this->actAsGuest($other, $otherSecret);
        $this->client->request('POST', $url, ['_token' => $this->guestToken($wedding), 'caption' => 'Gekapert']);
        self::assertResponseStatusCodeSame(403);

        $this->client->getCookieJar()->clear();
        $this->actAsGuest($owner, $ownerSecret);
        $this->client->request('POST', $url, [
            '_token' => $this->guestToken($wedding),
            'caption' => '  Sonnenuntergang   am See ',
        ]);
        self::assertResponseRedirects();
        self::assertSame('Sonnenuntergang am See', self::fresh($media)->getCaption());
    }

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
            $this->fixtures->guestMedia(self::fresh($guest), Visibility::AllGuests, 0 === $i % 3 ? MediaType::Video : MediaType::Photo);
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

    private function challenge(Wedding $wedding, string $title, int $points): Challenge
    {
        $challenge = new Challenge(self::fresh($wedding), $title);
        $challenge->setPoints($points);
        self::em()->persist($challenge);
        self::em()->flush();

        return $challenge;
    }

    private function guestToken(Wedding $wedding): string
    {
        $crawler = $this->client->request('GET', "/h/{$wedding->getSlug()}/gaestebuch");
        if (200 !== $this->client->getResponse()->getStatusCode()) {
            $crawler = $this->client->request('GET', "/h/{$wedding->getSlug()}/album");
        }

        $token = $crawler->filter('input[name="_token"]')->first();

        return 0 === $token->count() ? $this->tokenFromGallery($wedding) : (string) $token->attr('value');
    }

    private function tokenFromGallery(Wedding $wedding): string
    {
        return (string) $this->client
            ->request('GET', "/h/{$wedding->getSlug()}/galerie")
            ->filter('input[name="_token"]')
            ->first()
            ->attr('value');
    }

    private function apiStart(Wedding $wedding, int $size, ?string $challenge = null): void
    {
        $this->client->request(
            'POST',
            "/h/{$wedding->getSlug()}/api/uploads",
            server: [
                'HTTP_X_REQUESTED_WITH' => 'wedora',
                'CONTENT_TYPE' => 'application/json',
            ],
            content: (string) json_encode(['type' => 'photo', 'size' => $size, 'challenge' => $challenge]),
        );
    }

    private function uploadPhoto(Wedding $wedding, string $content, ?string $challenge): string
    {
        $this->apiStart($wedding, strlen($content), $challenge);
        self::assertResponseStatusCodeSame(201);
        /** @var array{id: string} $start */
        $start = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->client->request(
            'PATCH',
            "/h/{$wedding->getSlug()}/api/uploads/{$start['id']}",
            server: [
                'HTTP_X_REQUESTED_WITH' => 'wedora',
                'HTTP_UPLOAD_OFFSET' => '0',
                'CONTENT_TYPE' => 'application/offset+octet-stream',
            ],
            content: $content,
        );
        self::assertResponseStatusCodeSame(201);
        /** @var array{media: array{id: string}} $done */
        $done = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $done['media']['id'];
    }
}
