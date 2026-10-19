<?php

declare(strict_types=1);

namespace App\Tests\Functional\Guest;

use App\Guest\Entity\Guest;
use App\Guest\JoinMethod;
use App\Guest\Repository\GuestRepository;
use App\Security\Guest\GuestCookie;
use App\Tests\Functional\WebTestCase;
use App\Wedding\Entity\Wedding;
use DateTimeImmutable;
use SensitiveParameter;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

final class JoinFlowTest extends WebTestCase
{
    public function testGuestJoinsViaOpenLinkAndChoosesName(): void
    {
        $wedding = $this->fixtures->wedding();

        $this->client->request('GET', self::openLink($wedding));
        self::assertResponseRedirects("/h/{$wedding->getSlug()}/join/name");

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Willkommen');

        $this->client->submitForm('Los geht’s', ['display_name[displayName]' => '  Sophie  ']);
        self::assertResponseRedirects("/h/{$wedding->getSlug()}");

        $cookie = $this->client->getCookieJar()->get(GuestCookie::NAME, GuestCookie::path($wedding->getSlug()));
        self::assertNotNull($cookie, 'Gast-Cookie muss auf den Pfad der Hochzeit beschränkt sein.');
        self::assertTrue($cookie->isHttpOnly());

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Hallo Sophie');

        $guest = self::service(GuestRepository::class)->findOneBy(['wedding' => $wedding]);
        self::assertInstanceOf(Guest::class, $guest);
        self::assertSame(JoinMethod::OpenLink, $guest->getJoinMethod());
        self::assertSame('Sophie', $guest->getDisplayName());
    }

    public function testInvalidOrDisabledEntryPointsLookIdentical(): void
    {
        $wedding = $this->fixtures->wedding();
        $archived = $this->fixtures->wedding();
        $archived->archive();
        $disabled = $this->fixtures->wedding();
        $disabled->access()->enableOpenLink(false);
        $this->fixtures->flush();

        foreach ([
            "/h/{$wedding->getSlug()}/join?k=falsch",
            "/h/{$wedding->getSlug()}/join",
            '/h/gibt-es-nicht/join?k=' . $wedding->access()->openLinkToken(),
            self::openLink($archived),
            self::openLink($disabled),
            '/i/' . str_repeat('A', 32),
        ] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }

    public function testNameStepCannotBeReachedWithoutValidLink(): void
    {
        $wedding = $this->fixtures->wedding();

        $this->client->request('GET', "/h/{$wedding->getSlug()}/join/name");
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', "/h/{$wedding->getSlug()}/join/name", ['display_name' => [
            'displayName' => 'Eve',
        ]]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, self::service(GuestRepository::class)->count(['wedding' => $wedding]));
    }

    public function testOpenLinkWithPasswordRequiresCorrectPassword(): void
    {
        $wedding = $this->weddingWithPassword('Rosengarten2027');

        $this->client->request('GET', self::openLink($wedding));
        self::assertResponseRedirects("/h/{$wedding->getSlug()}/join/password");

        // Passwortschritt lässt sich nicht überspringen.
        $this->client->request('GET', "/h/{$wedding->getSlug()}/join/name");
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', "/h/{$wedding->getSlug()}/join/password");
        $this->client->submitForm('Weiter', ['wedding_password[password]' => 'falsch']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert', 'stimmt leider nicht');

        $this->client->submitForm('Weiter', ['wedding_password[password]' => 'Rosengarten2027']);
        self::assertResponseRedirects("/h/{$wedding->getSlug()}/join/name");
    }

    public function testPasswordGuessingIsRateLimited(): void
    {
        $wedding = $this->weddingWithPassword('Rosengarten2027');
        $this->client->request('GET', self::openLink($wedding));
        $this->client->request('GET', "/h/{$wedding->getSlug()}/join/password");

        for ($i = 0; $i < 10; ++$i) {
            $this->client->submitForm('Weiter', ['wedding_password[password]' => "falsch{$i}"]);
            self::assertResponseStatusCodeSame(422);
        }

        // Auch das richtige Passwort wird während der Sperre abgelehnt.
        $this->client->submitForm('Weiter', ['wedding_password[password]' => 'Rosengarten2027']);
        self::assertResponseStatusCodeSame(429);
    }

    public function testInvitationSkipsPasswordAndIsRecordedOnGuest(): void
    {
        $wedding = $this->weddingWithPassword('Rosengarten2027');
        [$invitation, $token] = $this->fixtures->invitation($wedding);

        $this->client->request('GET', "/i/{$token}");
        self::assertResponseRedirects("/h/{$wedding->getSlug()}/join/name");

        $this->client->followRedirect();
        $this->client->submitForm('Los geht’s', ['display_name[displayName]' => 'Oma Helga']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Hallo Oma Helga');

        $guest = self::service(GuestRepository::class)->findOneBy(['wedding' => $wedding]);
        self::assertNotNull($guest);
        self::assertSame($invitation->getId()->toRfc4122(), $guest->getInvitation()?->getId()->toRfc4122());
        self::assertSame(JoinMethod::Invitation, $guest->getJoinMethod());
    }

    public function testRevokedExpiredOrDisabledInvitationsCannotBeUsed(): void
    {
        $wedding = $this->fixtures->wedding();
        [$revoked, $revokedToken] = $this->fixtures->invitation($wedding);
        $revoked->revoke(new DateTimeImmutable());
        [, $expiredToken] = $this->fixtures->invitation($wedding, 'Abgelaufen', new DateTimeImmutable('-1 day'));
        $this->fixtures->flush();

        foreach ([$revokedToken, $expiredToken] as $token) {
            $this->client->request('GET', "/i/{$token}");
            self::assertResponseStatusCodeSame(404);
        }

        [, $token] = $this->fixtures->invitation(self::fresh($wedding));
        self::fresh($wedding)->access()->enableInvitations(false);
        $this->fixtures->flush();
        $this->client->request('GET', "/i/{$token}");
        self::assertResponseStatusCodeSame(404);
    }

    public function testInvitationRevokedDuringJoinBlocksNameStep(): void
    {
        $wedding = $this->fixtures->wedding();
        [$invitation, $token] = $this->fixtures->invitation($wedding);
        $this->client->request('GET', "/i/{$token}");
        $this->client->request('GET', "/h/{$wedding->getSlug()}/join/name");
        self::assertResponseIsSuccessful('Kontrolle: ohne Widerruf ist der Namensschritt erreichbar.');

        self::fresh($invitation)->revoke(new DateTimeImmutable());
        $this->fixtures->flush();

        $this->client->request('GET', "/h/{$wedding->getSlug()}/join/name");
        self::assertResponseStatusCodeSame(404);
    }

    public function testJoinedGuestOpeningLinkAgainLandsOnHome(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest, $secret] = $this->fixtures->guest($wedding);
        $this->actAsGuest($guest, $secret);

        $this->client->request('GET', self::openLink($wedding));
        self::assertResponseRedirects("/h/{$wedding->getSlug()}");
        self::assertSame(1, self::service(GuestRepository::class)->count(['wedding' => $wedding]));
    }

    private function weddingWithPassword(#[SensitiveParameter] string $password): Wedding
    {
        $wedding = $this->fixtures->wedding();
        $hash = self::service(PasswordHasherFactoryInterface::class)
            ->getPasswordHasher('wedding_password')
            ->hash($password);
        $wedding->access()->setPasswordHash($hash);
        $this->fixtures->flush();

        return $wedding;
    }

    private static function openLink(Wedding $wedding): string
    {
        return "/h/{$wedding->getSlug()}/join?k={$wedding->access()->openLinkToken()}";
    }
}
