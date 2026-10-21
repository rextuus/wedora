<?php

declare(strict_types=1);

namespace App\Tests\Functional\App;

use App\Account\WeddingPermission;
use App\Challenge\Entity\Challenge;
use App\Guest\Entity\Guest;
use App\Tests\Functional\WebTestCase;
use App\Wedding\Entity\Wedding;
use SensitiveParameter;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Glücksrad: Gäste sehen nur First/Last Drink und Gezogenes, ziehen immer eine Aufgabe und dürfen erst nach dem
 * Erledigen weiterdrehen. Nicht gezogene Aufgaben sind auch per Link und Upload gesperrt.
 */
final class ChallengeWheelTest extends WebTestCase
{
    public function testCoupleSwitchesTheWheelOnInTheSettings(): void
    {
        $wedding = $this->fixtures->wedding();
        $user = $this->fixtures->user();
        $this->fixtures->member($user, $wedding, [WeddingPermission::Configure]);
        $this->client->loginUser(self::fresh($user));

        $crawler = $this->client->request('GET', "/admin/h/{$wedding->getSlug()}/einstellungen");
        $form = $crawler->selectButton('Module speichern')->form();
        $checkbox = $form->get('challenge_wheel');
        self::assertInstanceOf(ChoiceFormField::class, $checkbox);
        $checkbox->tick();
        $this->client->submit($form);

        self::assertTrue(self::fresh($wedding)->drawsChallengesByWheel());
    }

    public function testGuestsDrawOneChallengeAtATime(): void
    {
        [$wedding, $first, $tasks] = $this->weddingWithWheel();
        [$guest, $secret] = $this->fixtures->guest(self::fresh($wedding));
        $this->actAsGuest($guest, $secret);

        $crawler = $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben");
        self::assertSelectorTextContains('.challenge-list', $first->getTitle(), 'First Drink bleibt sichtbar.');
        foreach ($tasks as $task) {
            self::assertSelectorTextNotContains('.challenge-list', $task->getTitle(), 'Ungezogenes ist verborgen.');
        }

        $token = $crawler->filter('.wheel-form input[name="_token"]')->attr('value');
        foreach ($tasks as $task) {
            $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben/{$task->getId()}");
            self::assertResponseStatusCodeSame(404, 'Auch nicht per Link.');
        }

        $drawn = $this->spin($wedding, (string) $token);
        $titles = array_map(static fn(Challenge $c): string => $c->getTitle(), $tasks);
        self::assertContains($drawn['title'], $titles, 'Nur aus dem Rad, nie First Drink.');

        self::assertSame($drawn['id'], $this->spin($wedding, (string) $token)['id'], 'Erst erledigen, dann weiter.');
        $this->client->request('GET', $drawn['url']);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben");
        self::assertSelectorTextContains('.challenge-list', $drawn['title']);
        self::assertSelectorTextContains('.wheel-card', 'Deine Aufgabe vom Glücksrad');

        $other = array_values(array_filter(
            $tasks,
            static fn(Challenge $c): bool => $c->getTitle() !== $drawn['title'],
        ));
        $this->startUpload($wedding, $other[0]);
        self::assertResponseStatusCodeSame(409, 'Kein Upload zu Ungezogenem.');

        $this->complete($guest, $drawn['id']);
        $next = $this->spin($wedding, (string) $token);
        self::assertNotSame($drawn['id'], $next['id'], 'Nach dem Erledigen gibt es eine neue Aufgabe.');

        $this->complete($guest, $next['id']);
        $this->spin($wedding, (string) $token, 409);
        $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben");
        self::assertSelectorTextContains('.wheel-card', 'nichts mehr zu ziehen');
    }

    public function testWithoutJavaScriptTheSpinLeadsStraightToTheChallenge(): void
    {
        [$wedding] = $this->weddingWithWheel();
        [$guest, $secret] = $this->fixtures->guest(self::fresh($wedding));
        $this->actAsGuest($guest, $secret);

        $crawler = $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben");
        $this->client->submit($crawler->selectButton('Drehen')->form());
        self::assertResponseRedirects();
        self::assertStringContainsString(
            "/h/{$wedding->getSlug()}/aufgaben/",
            (string) $this->client->getResponse()->headers->get('Location'),
        );
    }

    public function testWithoutTheWheelEverythingIsVisible(): void
    {
        [$wedding, , $tasks] = $this->weddingWithWheel();
        self::fresh($wedding)->drawChallengesByWheel(false);
        self::em()->flush();
        [$guest, $secret] = $this->fixtures->guest(self::fresh($wedding));
        $this->actAsGuest($guest, $secret);

        $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben");
        self::assertSelectorNotExists('.wheel-card');
        foreach ($tasks as $task) {
            self::assertSelectorTextContains('.challenge-list', $task->getTitle());
        }
    }

    /**
     * @return array{Wedding, Challenge, list<Challenge>}
     */
    private function weddingWithWheel(): array
    {
        $wedding = $this->fixtures->wedding();
        $fresh = self::fresh($wedding);
        $fresh->drawChallengesByWheel(true);
        $first = new Challenge($fresh, 'First Drink');
        $tasks = [new Challenge($fresh, 'Tanz mit der Braut'), new Challenge($fresh, 'Foto mit der Torte')];
        foreach ([$first, ...$tasks] as $challenge) {
            self::em()->persist($challenge);
        }

        self::em()->flush();

        return [$wedding, $first, $tasks];
    }

    /**
     * @return array{id: string, title: string, url: string}
     */
    private function spin(Wedding $wedding, #[SensitiveParameter] string $token, int $status = 200): array
    {
        $this->client->request(
            'POST',
            "/h/{$wedding->getSlug()}/aufgaben/gluecksrad",
            ['_token' => $token],
            server: ['HTTP_ACCEPT' => 'application/json'],
        );
        self::assertResponseStatusCodeSame($status);

        /** @var array{challenge: array{id: string, title: string, url: string}|null} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $data['challenge'] ?? ['id' => '', 'title' => '', 'url' => ''];
    }

    private function startUpload(Wedding $wedding, Challenge $challenge): void
    {
        $this->client->request(
            'POST',
            "/h/{$wedding->getSlug()}/api/uploads",
            server: ['HTTP_X_REQUESTED_WITH' => 'wedora', 'CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'type' => 'photo',
                'size' => 1000,
                'challenge' => $challenge->getId()->toRfc4122(),
            ]),
        );
    }

    /** Ein Beitrag zur Aufgabe macht sie erledigt. */
    private function complete(Guest $guest, string $challengeId): void
    {
        $media = $this->fixtures->guestMedia(self::fresh($guest));
        $challenge = self::em()->find(Challenge::class, $challengeId);
        $media->assignChallenge($challenge);
        self::em()->flush();
    }
}
