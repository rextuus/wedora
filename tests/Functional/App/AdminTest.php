<?php

declare(strict_types=1);

namespace App\Tests\Functional\App;

use App\Account\Entity\User;
use App\Account\Repository\UserRepository;
use App\Account\Repository\WeddingMemberRepository;
use App\Account\WeddingPermission;
use App\Challenge\Repository\ChallengeRepository;
use App\Gallery\GalleryQuery;
use App\Media\ExportStatus;
use App\Media\PublicationStatus;
use App\Media\Repository\ExportRepository;
use App\Media\Storage\MediaFile;
use App\Media\Storage\MediaStorage;
use App\Social\Entity\MediaReport;
use App\Social\ReportReason;
use App\Tests\Functional\WebTestCase;
use App\Wedding\Entity\Wedding;
use App\Wedding\ModerationMode;
use App\Wedding\ModuleType;
use App\Wedding\PublicLinks;
use App\Wedding\Repository\InvitationRepository;
use App\Wedding\Repository\WeddingRepository;
use DateTimeImmutable;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;

final class AdminTest extends WebTestCase
{
    protected function tearDown(): void
    {
        $root = $_SERVER['MEDIA_ROOT'] ?? null;
        new Filesystem()->remove(is_string($root) ? $root : '/tmp/wedora-test-media');
        parent::tearDown();
    }

    public function testPagesFollowExplicitPermissions(): void
    {
        $wedding = $this->fixtures->wedding();
        $slug = $wedding->getSlug();
        $this->login([WeddingPermission::ViewContent], $wedding);

        $this->client->request('GET', "/admin/h/{$slug}");
        self::assertResponseIsSuccessful();
        foreach (['moderation', 'zugang', 'aufgaben', 'einstellungen', 'export'] as $page) {
            $this->client->request('GET', "/admin/h/{$slug}/{$page}");
            self::assertResponseStatusCodeSame(403, $page);
        }

        foreach (['team', 'loeschen'] as $page) {
            $this->client->request('GET', "/admin/h/{$slug}/{$page}");
            self::assertResponseStatusCodeSame(403, "{$page}: nur Plattform");
        }

        $this->client->request('GET', '/admin/h/' . $this->fixtures->wedding()->getSlug());
        self::assertResponseStatusCodeSame(404, 'fremde Hochzeit nicht erkennbar');
    }

    public function testPlatformAdminManagesButSeesNoContent(): void
    {
        $wedding = $this->fixtures->wedding();
        $this->loginPlatformAdmin();

        foreach (['', '/zugang', '/einstellungen', '/aufgaben', '/team'] as $page) {
            $this->client->request('GET', "/admin/h/{$wedding->getSlug()}{$page}");
            self::assertResponseIsSuccessful($page);
        }

        foreach (['/moderation', '/export'] as $page) {
            $this->client->request('GET', "/admin/h/{$wedding->getSlug()}{$page}");
            self::assertResponseStatusCodeSame(403, "{$page}: Inhalte nur mit Mitgliedschaft");
        }
    }

    public function testModerationApproveRejectDeleteAndReports(): void
    {
        $wedding = $this->fixtures->wedding(ModerationMode::Approval);
        [$guest] = $this->fixtures->guest($wedding);
        [$reporter] = $this->fixtures->guest($wedding, 'Meldet');
        $toApprove = $this->fixtures->guestMedia($guest);
        $toReject = $this->fixtures->guestMedia($guest);
        $toDelete = $this->fixtures->guestMedia($guest);
        self::service(MediaStorage::class)
            ->store($toDelete->mediaId(), MediaFile::Original, dirname(__DIR__, 2) . '/Fixtures/phase0.jpg');
        $reported = $this->fixtures->guestMedia($guest);
        self::fresh($reported)->approve(new DateTimeImmutable());
        self::em()->persist(new MediaReport(self::fresh($reported), self::fresh($reporter), ReportReason::Privacy));
        self::em()->flush();
        $this->login([WeddingPermission::Moderate], $wedding);

        $crawler = $this->client->request('GET', "/admin/h/{$wedding->getSlug()}/moderation");
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Wartet auf Freigabe (3)', $crawler->text());
        self::assertStringContainsString('Meldungen (1)', $crawler->text());
        $token = $this->adminToken($crawler);

        foreach ([
            [$toApprove, 'freigeben'],
            [$toReject,  'ablehnen'],
            [$toDelete,  'loeschen'],
            [$reported,  'ablehnen'],
        ] as [$media, $action]) {
            $this->client->request(
                'POST',
                "/admin/h/{$wedding->getSlug()}/moderation/media/{$media->getId()->toRfc4122()}/{$action}",
                ['_token' => $token],
            );
            self::assertResponseRedirects();
        }

        self::assertSame(PublicationStatus::Published, self::fresh($toApprove)->getPublicationStatus());
        self::assertSame(PublicationStatus::Rejected, self::fresh($toReject)->getPublicationStatus());
        self::assertTrue(self::fresh($toDelete)->isDeleted());
        self::assertSame([], self::service(MediaStorage::class)->list($toDelete->mediaId()), 'Dateien gelöscht');

        $shared = self::service(GalleryQuery::class)->shared(self::fresh($reporter));
        self::assertSame(
            [$toApprove->getId()->toRfc4122()],
            array_map(static fn($m) => $m->getId()->toRfc4122(), $shared),
        );

        $crawler = $this->client->request('GET', "/admin/h/{$wedding->getSlug()}/moderation");
        self::assertStringContainsString('Meldungen (0)', $crawler->text(), 'Ausblenden erledigt die Meldung');

        $this->client->request(
            'POST',
            "/admin/h/{$wedding->getSlug()}/moderation/media/{$toApprove->getId()->toRfc4122()}/freigeben",
            // @mago-expect lint:no-literal-password
            ['_token' => 'falsch'],
        );
        self::assertResponseStatusCodeSame(403);
    }

    public function testSettingsModulesLifecycleAndLimits(): void
    {
        $wedding = $this->fixtures->wedding();
        $slug = $wedding->getSlug();
        $this->login([WeddingPermission::Configure, WeddingPermission::ManageLifecycle], $wedding);
        $token = $this->adminToken($this->client->request('GET', "/admin/h/{$slug}/einstellungen"));

        $this->client->request('POST', "/admin/h/{$slug}/einstellungen/allgemein", [
            '_token' => $token,
            'title' => 'Anna & Lukas ♥',
            'date' => '2027-07-18',
            'moderation' => 'post_moderation',
        ]);
        $this->client->request('POST', "/admin/h/{$slug}/einstellungen/module", [
            '_token' => $token,
            'enabled_gallery' => '1',
            'close_gallery' => '',
            'close_guestbook' => '',
            'close_challenges' => '2027-07-19T02:00',
            'points' => '1',
        ]);
        $this->client->request('POST', "/admin/h/{$slug}/einstellungen/lebenszyklus", [
            '_token' => $token,
            'action' => 'archivieren',
        ]);

        $fresh = self::fresh($wedding);
        self::em()->refresh($fresh);
        self::assertSame('Anna & Lukas ♥', $fresh->getTitle());
        self::assertSame('2027-07-18', $fresh->getWeddingDate()?->format('Y-m-d'));
        self::assertSame(ModerationMode::PostModeration, $fresh->getModerationMode());
        self::assertFalse($fresh->module(ModuleType::Guestbook)->isEnabled());
        self::assertSame(
            '2027-07-19 02:00',
            $fresh->module(ModuleType::Challenges)->getContributionsCloseAt()?->format('Y-m-d H:i'),
        );
        self::assertTrue($fresh->areChallengePointsEnabled());
        self::assertFalse($fresh->isActive());

        $this->client->request('POST', "/admin/h/{$slug}/einstellungen/limits", [
            '_token' => $token,
            'max_video_seconds' => 5,
        ]);
        self::assertResponseStatusCodeSame(403, 'Limits nur durch die Plattform');
    }

    public function testPlatformAdminSetsLimitsWithinPlatformCap(): void
    {
        $wedding = $this->fixtures->wedding();
        $this->loginPlatformAdmin();
        $token = $this->adminToken($this->client->request('GET', "/admin/h/{$wedding->getSlug()}/einstellungen"));

        $this->client->request('POST', "/admin/h/{$wedding->getSlug()}/einstellungen/limits", [
            '_token' => $token,
            'max_video_seconds' => 9999,
            'max_video_mb' => 200,
            'max_photo_mb' => 20,
            'storage_quota_gb' => 10,
            'guest_quota_gb' => '',
        ]);

        self::em()->refresh(self::fresh($wedding));
        self::assertSame(120, self::fresh($wedding)->limits()->maxVideoSeconds(), 'auf Plattform-Obergrenze begrenzt');
        self::assertSame(200 * 1024 * 1024, self::fresh($wedding)->limits()->maxVideoBytes());
        self::assertNull(self::fresh($wedding)->limits()->guestQuotaBytes());
    }

    public function testAccessInvitationsGuestsQrAndPassword(): void
    {
        $wedding = $this->fixtures->wedding();
        $slug = $wedding->getSlug();
        $oldToken = $wedding->access()->openLinkToken();
        [$guest] = $this->fixtures->guest($wedding, 'Max');
        $this->login([WeddingPermission::ManageAccess], $wedding);
        $crawler = $this->client->request('GET', "/admin/h/{$slug}/zugang");
        $token = $this->adminToken($crawler);

        $this->client->request('GET', "/admin/h/{$slug}/zugang/qr.svg");
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');
        self::assertStringContainsString('<svg', (string) $this->client->getResponse()->getContent());

        // Test-Code fürs Heimnetz nur, wenn lokal eine Heimnetz-Adresse eingestellt ist (LAN_BASE_URL).
        $lan = self::service(PublicLinks::class)->lanJoin(self::fresh($wedding));
        $this->client->request('GET', "/admin/h/{$slug}/zugang/qr.svg?netz=lan");
        self::assertResponseStatusCodeSame(null === $lan ? 404 : 200);
        $this->client->request('GET', "/admin/h/{$slug}/zugang");
        self::assertSelectorCount(null === $lan ? 1 : 2, '.qr-pair img.qr');

        $this->client->request('POST', "/admin/h/{$slug}/zugang/einladungen", [
            '_token' => $token,
            'label' => 'Familie Müller',
            'expires' => '',
        ]);
        $crawler = $this->client->followRedirect();
        $link = $crawler->filter('.notice--success code')->text();
        self::assertMatchesRegularExpression(
            '#^http://localhost:8180/i/[A-Za-z0-9_-]{32}$#',
            $link,
            'immer die öffentliche Adresse, nicht die des Aufrufs',
        );
        $crawler = $this->client->request('GET', "/admin/h/{$slug}/zugang");
        self::assertCount(0, $crawler->filter('.notice--success code'), 'Link wird nur einmal angezeigt');

        $invitation = self::service(InvitationRepository::class)->findOneBy(['wedding' => self::fresh($wedding)]);
        self::assertNotNull($invitation);
        $this->client->request(
            'POST',
            "/admin/h/{$slug}/zugang/einladungen/{$invitation->getId()->toRfc4122()}/widerrufen",
            ['_token' => $token],
        );
        self::assertTrue(self::fresh($invitation)->isRevoked());

        $this->client->request('POST', "/admin/h/{$slug}/zugang/gaeste/{$guest->getId()->toRfc4122()}/sperren", [
            '_token' => $token,
        ]);
        self::assertTrue(self::fresh($guest)->isBlocked());

        $this->client->request('POST', "/admin/h/{$slug}/zugang/link", ['_token' => $token, 'action' => 'neu']);
        self::assertNotSame($oldToken, self::fresh($wedding)->access()->openLinkToken());

        // @mago-expect lint:no-literal-password
        $this->client->request('POST', "/admin/h/{$slug}/zugang/passwort", ['_token' => $token, 'password' => 'kurz']);
        self::assertFalse(self::fresh($wedding)->access()->requiresPassword());
        $this->client->request('POST', "/admin/h/{$slug}/zugang/passwort", [
            '_token' => $token,
            // @mago-expect lint:no-literal-password
            'password' => 'Rosengarten',
        ]);
        self::assertTrue(self::fresh($wedding)->access()->requiresPassword());
    }

    public function testChallengesFromTemplateEditAndDelete(): void
    {
        $wedding = $this->fixtures->wedding();
        $slug = $wedding->getSlug();
        $this->login([WeddingPermission::Configure], $wedding);
        $token = $this->adminToken($this->client->request('GET', "/admin/h/{$slug}/aufgaben"));

        $this->client->request('POST', "/admin/h/{$slug}/aufgaben/neu", [
            '_token' => $token,
            'template' => 'first-drink',
        ]);
        $this->client->request('POST', "/admin/h/{$slug}/aufgaben/neu", [
            '_token' => $token,
            'title' => 'Tortenanschnitt',
            'description' => 'Fang den Moment ein',
            'type' => 'video',
            'points' => 20,
            'starts_at' => '2027-07-18T19:30',
            'ends_at' => '2027-07-18T18:00',
            'enabled' => '1',
        ]);
        self::assertCount(
            1,
            self::service(ChallengeRepository::class)->forWedding(self::fresh($wedding)),
            'Ende vor Beginn wird abgelehnt',
        );

        $challenge = self::service(ChallengeRepository::class)->forWedding(self::fresh($wedding))[0];
        self::assertSame('First Drink', $challenge->getTitle());
        $this->client->request('POST', "/admin/h/{$slug}/aufgaben/{$challenge->getId()->toRfc4122()}", [
            '_token' => $token,
            'title' => 'Erster Drink',
            'description' => '',
            'type' => 'any',
            'points' => 5,
            'starts_at' => '',
            'ends_at' => '',
        ]);
        $edited = self::fresh($challenge);
        self::em()->refresh($edited);
        self::assertSame('Erster Drink', $edited->getTitle());
        self::assertFalse($edited->isEnabled(), 'ohne Häkchen ausgeblendet');

        $this->client->request('POST', "/admin/h/{$slug}/aufgaben/{$challenge->getId()->toRfc4122()}", [
            '_token' => $token,
            'action' => 'loeschen',
        ]);
        self::assertSame([], self::service(ChallengeRepository::class)->forWedding(self::fresh($wedding)));
    }

    public function testExportRequestCreatesDownload(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest] = $this->fixtures->guest($wedding);
        $media = $this->fixtures->guestMedia($guest);
        self::service(MediaStorage::class)
            ->store($media->mediaId(), MediaFile::Original, dirname(__DIR__, 2) . '/Fixtures/phase0.jpg');
        $this->login([WeddingPermission::Export], $wedding);
        $token = $this->adminToken($this->client->request('GET', "/admin/h/{$wedding->getSlug()}/export"));

        $this->client->request('POST', "/admin/h/{$wedding->getSlug()}/export", ['_token' => $token]);
        $crawler = $this->client->followRedirect();

        $export = self::service(ExportRepository::class)->findOneBy(['wedding' => self::fresh($wedding)]);
        self::assertNotNull($export);
        self::assertSame(ExportStatus::Ready, $export->getStatus());
        self::assertSame(1, $export->getItemCount());
        self::assertCount(1, $crawler->filter('a:contains("Herunterladen")'));
    }

    public function testPlatformAdminCreatesWeddingAddsTeamAndDeletes(): void
    {
        $this->loginPlatformAdmin();
        $crawler = $this->client->request('GET', '/admin/neu');
        $token = $this->adminToken($crawler);
        $slug = 'neue-hochzeit-' . bin2hex(random_bytes(2));

        $this->client->request('POST', '/admin/neu', [
            '_token' => $token,
            'title' => 'Mia & Ben',
            'slug' => $slug,
            'date' => '',
        ]);
        self::assertResponseRedirects("/admin/h/{$slug}");
        $wedding = self::service(WeddingRepository::class)->findOneBySlug($slug);
        self::assertNotNull($wedding);

        $this->client->request('POST', '/admin/neu', ['_token' => $token, 'title' => 'Doppelt', 'slug' => $slug]);
        self::assertSelectorTextContains('.notice--error', 'bereits vergeben');

        $email = "paar-{$slug}@wedora.test";
        $this->client->request('POST', "/admin/h/{$slug}/team", [
            '_token' => $token,
            'email' => $email,
            'name' => 'Mia',
            // @mago-expect lint:no-literal-password
            'password' => 'ein-sicheres-passwort',
            'label' => 'Brautpaar',
            'permissions' => ['view_content', 'moderate', 'nonsense'],
        ]);
        $user = self::service(UserRepository::class)->findOneByEmail($email);
        self::assertNotNull($user);
        $member = self::service(WeddingMemberRepository::class)->findOneFor($user, $wedding);
        self::assertSame([WeddingPermission::Moderate, WeddingPermission::ViewContent], $member?->getPermissions());

        $crawler = $this->client->request('GET', "/admin/h/{$slug}/team");
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($email, $crawler->filter('.row-list')->text());
        self::assertStringContainsString('Moderieren', $crawler->filter('.row-list')->text());

        $this->client->request('POST', "/admin/h/{$slug}/loeschen", ['_token' => $token, 'confirm' => 'falsch']);
        self::assertNotNull(self::service(WeddingRepository::class)->findOneBySlug($slug));
        $this->client->request('POST', "/admin/h/{$slug}/loeschen", ['_token' => $token, 'confirm' => $slug]);
        self::assertResponseRedirects('/admin');
        self::em()->clear();
        self::assertNull(self::service(WeddingRepository::class)->findOneBySlug($slug));
    }

    /**
     * @param list<WeddingPermission> $permissions
     */
    private function login(array $permissions, Wedding $wedding): User
    {
        $user = $this->fixtures->user();
        $this->fixtures->member($user, self::fresh($wedding), $permissions);
        $this->client->loginUser(self::fresh($user));

        return $user;
    }

    private function loginPlatformAdmin(): User
    {
        $user = $this->fixtures->user(true);
        $this->client->loginUser(self::fresh($user));

        return $user;
    }

    private function adminToken(Crawler $crawler): string
    {
        return (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
    }
}
