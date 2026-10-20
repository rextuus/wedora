<?php

declare(strict_types=1);

namespace App\Tests\Functional\Guest;

use App\Account\WeddingPermission;
use App\Guest\Repository\GuestRepository;
use App\Security\Guest\GuestCookie;
use App\Tests\Functional\WebTestCase;
use App\Wedding\Entity\Wedding;

/** Codewort zum Wiederherstellen des Gastzugangs: Name + eigenes Codewort aus drei, ein Versuch alle 15 Minuten. */
final class CodewordTest extends WebTestCase
{
    public function testNewGuestGetsAMemorableCodewordShownInAlbumAndAdmin(): void
    {
        $wedding = $this->fixtures->wedding();
        $this->join($wedding, 'Sophie');
        self::assertResponseRedirects("/h/{$wedding->getSlug()}/codewort?weiter=start");
        $this->client->followRedirect();

        $guest = self::service(GuestRepository::class)->findOneBy(['wedding' => $wedding]);
        $codeword = (string) $guest?->getCodeword();
        self::assertMatchesRegularExpression('/^[A-Z][a-z]+[1-9][0-9]$/', $codeword, 'z. B. Kuchen13');
        self::assertSelectorTextContains('.codeword', $codeword);
        self::assertSelectorTextContains('main', 'Screenshot');

        $this->client->request('GET', "/h/{$wedding->getSlug()}/album");
        $this->client->clickLink('Mein Codewort zum Wiederreinkommen');
        self::assertSelectorTextContains('.codeword', $codeword);

        $user = $this->fixtures->user();
        $this->fixtures->member($user, self::fresh($wedding), [WeddingPermission::ManageAccess]);
        $this->client->getCookieJar()->clear();
        $this->client->loginUser(self::fresh($user));
        $this->client->request('GET', "/admin/h/{$wedding->getSlug()}/zugang");
        self::assertSelectorTextContains('main', 'Codewort ' . $codeword);
    }

    public function testGuestComesBackWithNameAndCodewordOnAnotherDevice(): void
    {
        $wedding = $this->fixtures->wedding();
        $this->join($wedding, 'Oma Helga');
        $guest = self::service(GuestRepository::class)->findOneBy(['wedding' => $wedding]);
        $codeword = (string) $guest?->getCodeword();
        $oldCookie = $this->client->getCookieJar()->get(GuestCookie::NAME, GuestCookie::path($wedding->getSlug()));
        self::assertNotNull($oldCookie);

        // Anderes Gerät: Beitrittslink, dann „Ich war schon dabei“.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', "/h/{$wedding->getSlug()}/join?k={$wedding->access()->openLinkToken()}");
        $crawler = $this->client->followRedirect();
        $crawler = $this->client->click($crawler->selectLink('Ich war schon dabei und habe ein Codewort')->link());
        $crawler = $this->client->submit($crawler->selectButton('Weiter')->form(['name' => '  oma helga ']));
        $choices = $crawler->filter('.codeword-choice')->each(static fn($b): string => trim($b->text()));
        self::assertCount(3, $choices);
        self::assertContains($codeword, $choices);
        self::assertSame(
            $choices,
            (static function (array $c): array {
                sort($c);
                return $c;
            })($choices),
            'Alphabetisch – die Position verrät nichts.',
        );

        // Neu laden: dieselbe Auswahl (sonst ließe sich das echte Codewort durch Vergleichen erraten).
        $crawler = $this->client->request('POST', "/h/{$wedding->getSlug()}/join/wieder", ['name' => 'Oma Helga']);
        self::assertSame($choices, $crawler->filter('.codeword-choice')->each(static fn($b): string => trim(
            $b->text(),
        )));

        $this->client->submit($crawler->selectButton($codeword)->form());
        self::assertResponseRedirects("/h/{$wedding->getSlug()}");
        $this->client->followRedirect();
        self::assertSelectorTextContains('.notice', 'Willkommen zurück, Oma Helga');
        self::assertSelectorTextContains('h1', 'Hallo Oma Helga');

        // Das alte Gerät ist abgemeldet (neues Geheimnis).
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($oldCookie);
        $this->client->request('GET', "/h/{$wedding->getSlug()}");
        self::assertFalse($this->client->getResponse()->isSuccessful());
    }

    public function testOneWrongGuessLocksTheNameForFifteenMinutes(): void
    {
        $wedding = $this->fixtures->wedding();
        $this->join($wedding, 'Ben');
        $codeword = (string) self::service(GuestRepository::class)->findOneBy(['wedding' => $wedding])?->getCodeword();
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', "/h/{$wedding->getSlug()}/join?k={$wedding->access()->openLinkToken()}");

        $crawler = $this->client->request('POST', "/h/{$wedding->getSlug()}/join/wieder", ['name' => 'Ben']);
        $wrong = array_values(array_filter($crawler->filter('.codeword-choice')->each(static fn($b): string => trim(
            $b->text(),
        )), static fn(string $c): bool => $c !== $codeword))[0];
        $this->client->submit($crawler->selectButton($wrong)->form());
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.notice--error', 'nicht dein Codewort');

        $crawler = $this->client->request('POST', "/h/{$wedding->getSlug()}/join/wieder", ['name' => 'Ben']);
        $this->client->submit($crawler->selectButton($codeword)->form());
        self::assertResponseStatusCodeSame(422, 'Auch das richtige erst nach 15 Minuten.');
        self::assertSelectorTextContains('.notice--error', 'Zu viele Versuche');
    }

    public function testOnlyWithAValidJoinLinkAndKnownName(): void
    {
        $wedding = $this->fixtures->wedding();
        $this->join($wedding, 'Lea');
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', "/h/{$wedding->getSlug()}/join/wieder");
        self::assertResponseStatusCodeSame(404, 'Ohne Beitrittslink keine Wiederherstellung.');

        $this->client->request('GET', "/h/{$wedding->getSlug()}/join?k={$wedding->access()->openLinkToken()}");
        $this->client->request('POST', "/h/{$wedding->getSlug()}/join/wieder", ['name' => 'Niemand']);
        self::assertSelectorTextContains('.notice--error', 'finden wir niemanden');
        self::assertSelectorNotExists('.codeword-choice');
    }

    private function join(Wedding $wedding, string $name): void
    {
        $this->client->request('GET', "/h/{$wedding->getSlug()}/join?k={$wedding->access()->openLinkToken()}");
        $this->client->followRedirect();
        $this->client->submitForm('Los geht’s', ['display_name[displayName]' => $name]);
    }
}
