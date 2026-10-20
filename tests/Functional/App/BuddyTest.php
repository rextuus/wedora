<?php

declare(strict_types=1);

namespace App\Tests\Functional\App;

use App\Account\WeddingPermission;
use App\Challenge\BuddyStatus;
use App\Challenge\ChallengeMediaType;
use App\Challenge\Entity\BuddyAssignment;
use App\Challenge\Entity\Challenge;
use App\Challenge\PortraitBuddy;
use App\Guest\Entity\Guest;
use App\Guest\Portrait\PortraitStorage;
use App\Tests\Functional\WebTestCase;
use App\Wedding\Entity\Wedding;
use DateTimeImmutable;
use Symfony\Component\Filesystem\Filesystem;

/** „Foto mit gezeichnetem Gast“: Partner von der Gästewand zulosen, ohne jemanden zu überrennen. */
final class BuddyTest extends WebTestCase
{
    protected function tearDown(): void
    {
        $root = $_SERVER['MEDIA_ROOT'] ?? null;
        new Filesystem()->remove(is_string($root) ? $root : '/tmp/wedora-test-media');
        parent::tearDown();
    }

    public function testGuestGetsAPartnerFromTheWallAndThePhotoCompletesIt(): void
    {
        $wedding = $this->wedding();
        $challenge = $this->buddyChallenge($wedding);
        [$me, $secret] = $this->fixtures->guest(self::fresh($wedding), 'Ich');
        [$lea] = $this->fixtures->guest(self::fresh($wedding), 'Lea');
        $this->drawn($lea, shared: true);
        $this->actAsGuest($me, $secret);
        $url = "/h/{$wedding->getSlug()}/aufgaben/{$challenge->getId()->toRfc4122()}";
        $photo = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/phase0.jpg');

        // Ohne eigenes freigegebenes Porträt: erst zeichnen lassen; Hochladen geht auch nicht.
        $this->client->request('GET', $url);
        self::assertSelectorTextContains('.mission', 'Erst zeichnen lassen');
        $this->apiStart($wedding, strlen($photo), $challenge);
        self::assertResponseStatusCodeSame(409);

        $this->drawn($me, shared: true);
        $crawler = $this->client->request('GET', $url);
        $this->client->submit($crawler->selectButton('Wen soll ich suchen?')->form());
        self::assertResponseRedirects($url);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.buddy__name', 'Lea');
        self::assertSelectorExists('.buddy__art [aria-label*="Lea"], .buddy__art img[alt*="Lea"]');

        $mediaId = $this->upload($wedding, $photo, $challenge);
        $assignment = self::em()
            ->getRepository(BuddyAssignment::class)
            ->findOneBy(['seeker' => self::fresh($me)]);
        self::assertSame($mediaId, $assignment?->getMedia()?->getId()->toRfc4122(), 'Das Foto hakt die Zulosung ab.');
        $crawler = $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben");
        self::assertContains('Foto mit einem gezeichneten Gast', $crawler
            ->filter('.challenge-item.is-done h2')
            ->each(static fn($h): string => trim($h->text())));
    }

    public function testRulesProtectPopularGuestsAndGreyOutTheTask(): void
    {
        $wedding = $this->wedding();
        $challenge = $this->buddyChallenge($wedding);
        self::fresh($challenge)->setBuddyRules(1, 30);
        self::em()->flush();
        [$me, $secret] = $this->fixtures->guest(self::fresh($wedding), 'Ich');
        [$ben] = $this->fixtures->guest(self::fresh($wedding), 'Ben');
        [$lea] = $this->fixtures->guest(self::fresh($wedding), 'Lea');
        [$tom] = $this->fixtures->guest(self::fresh($wedding), 'Tom');
        foreach ([$me, $ben, $lea, $tom] as $g) {
            $this->drawn($g, shared: true);
        }

        $buddies = self::service(PortraitBuddy::class);
        // Lea hat schon ihr eines Foto (Limit 1), Tom wird gerade von Ben gesucht (Pause).
        $done = new BuddyAssignment(
            self::fresh($challenge),
            self::fresh($ben),
            self::fresh($lea),
            new DateTimeImmutable('-3 hours'),
        );
        $done->completeWith($this->fixtures->guestMedia(self::fresh($ben)), new DateTimeImmutable('-3 hours'));
        self::em()->persist($done);
        self::em()->persist(
            new BuddyAssignment(
                self::fresh($challenge),
                self::fresh($tom),
                self::fresh($ben),
                new DateTimeImmutable('-1 minute'),
            ),
        );
        self::em()->persist(
            new BuddyAssignment(
                self::fresh($challenge),
                self::fresh($ben),
                self::fresh($tom),
                new DateTimeImmutable('-2 minutes'),
            ),
        );
        self::em()->flush();

        self::assertSame(
            BuddyStatus::NobodyFree,
            $buddies->status(self::fresh($me), self::fresh($challenge)),
            'Lea ausgelastet, Ben und Tom werden gerade gesucht.',
        );
        $this->actAsGuest($me, $secret);
        $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben");
        self::assertSelectorTextContains('.challenge-item.is-paused', 'Gerade niemand frei');
        self::assertSelectorNotExists('.challenge-item.is-paused .button');

        // Die beiden Suchen laufen ab (20 Minuten) → Ben und Tom sind wieder frei.
        self::em()
            ->getConnection()
            ->executeStatement('UPDATE buddy_assignment SET assigned_at = ? WHERE ended_at IS NULL', [new DateTimeImmutable(
                '-25 minutes',
            )->format('Y-m-d H:i:s')]);
        self::em()->clear();
        $assignment = $buddies->assign(self::fresh($me), self::fresh($challenge));
        self::assertContains($assignment?->getPartner()->getDisplayName(), ['Ben', 'Tom']);
    }

    public function testFewestPhotosFirstAndSkippingFreesThePartner(): void
    {
        $wedding = $this->wedding();
        $challenge = $this->buddyChallenge($wedding);
        [$me] = $this->fixtures->guest(self::fresh($wedding), 'Ich');
        [$ben] = $this->fixtures->guest(self::fresh($wedding), 'Ben');
        [$lea] = $this->fixtures->guest(self::fresh($wedding), 'Lea');
        [$tom] = $this->fixtures->guest(self::fresh($wedding), 'Tom');
        foreach ([$me, $ben, $lea, $tom] as $g) {
            $this->drawn($g, shared: true);
        }

        $old = new BuddyAssignment(
            self::fresh($challenge),
            self::fresh($ben),
            self::fresh($lea),
            new DateTimeImmutable('-3 hours'),
        );
        $old->completeWith($this->fixtures->guestMedia(self::fresh($ben)), new DateTimeImmutable('-3 hours'));
        self::em()->persist($old);
        self::em()->flush();

        $buddies = self::service(PortraitBuddy::class);
        $first = $buddies->assign(self::fresh($me), self::fresh($challenge));
        self::assertContains(
            $first?->getPartner()->getDisplayName(),
            ['Ben', 'Tom'],
            'Lea hatte schon ein Foto – erst die anderen.',
        );
        $buddies->skip(self::fresh($me), self::fresh($challenge));
        self::assertNull($buddies->current(self::fresh($me), self::fresh($challenge)));
        self::assertSame(
            BuddyStatus::Ready,
            $buddies->status(self::fresh($ben), self::fresh($challenge)),
            'Nach „Jemand anderen“ keine Pause.',
        );
    }

    public function testCoupleSetsTheRulesInTheAdmin(): void
    {
        $wedding = $this->wedding();
        $challenge = $this->buddyChallenge($wedding);
        $user = $this->fixtures->user();
        $this->fixtures->member($user, self::fresh($wedding), [WeddingPermission::Configure]);
        $this->client->loginUser(self::fresh($user));

        $crawler = $this->client->request('GET', "/admin/h/{$wedding->getSlug()}/aufgaben");
        $form = $crawler->filter('details form')->first()->form(['buddy_limit' => '5', 'buddy_pause' => '45']);
        $this->client->submit($form);
        self::assertSame(5, self::fresh($challenge)->getBuddyLimit());
        self::assertSame(45, self::fresh($challenge)->getBuddyPauseMinutes());
    }

    private function wedding(): Wedding
    {
        $wedding = $this->fixtures->wedding();
        self::fresh($wedding)->enablePortraits(true);
        self::em()->flush();

        return $wedding;
    }

    private function buddyChallenge(Wedding $wedding): Challenge
    {
        $challenge = new Challenge(self::fresh($wedding), 'Foto mit einem gezeichneten Gast');
        $challenge->setMediaType(ChallengeMediaType::Buddy);
        self::em()->persist($challenge);
        self::em()->flush();

        return $challenge;
    }

    private function drawn(Guest $guest, bool $shared = false): void
    {
        $guest = self::fresh($guest);
        $guest->portraitRequested(new DateTimeImmutable('-1 minute'));
        $guest->portraitDrawn(new DateTimeImmutable());
        $guest->sharePortrait($shared);
        $path = sys_get_temp_dir() . '/portrait-' . bin2hex(random_bytes(4)) . '.png';
        copy(dirname(__DIR__, 3) . '/assets/images/app-icon-192.png', $path);
        self::service(PortraitStorage::class)->savePortrait($guest, $path);
        self::em()->flush();
    }

    private function apiStart(Wedding $wedding, int $size, Challenge $challenge): void
    {
        $this->client->request(
            'POST',
            "/h/{$wedding->getSlug()}/api/uploads",
            server: ['HTTP_X_REQUESTED_WITH' => 'wedora', 'CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'type' => 'photo',
                'size' => $size,
                'challenge' => $challenge->getId()->toRfc4122(),
            ]),
        );
    }

    private function upload(Wedding $wedding, string $content, Challenge $challenge): string
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
