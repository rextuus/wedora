<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Account\WeddingPermission;
use App\Tests\Functional\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LoginTest extends WebTestCase
{
    // @mago-expect lint:no-literal-password
    private const string PASSWORD = 'ein-langes-passwort';

    public function testAccountLogsInAndSeesOwnWeddings(): void
    {
        $user = $this->fixtures->user();
        self::fresh($user)
            ->setPassword(self::service(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $wedding = $this->fixtures->wedding();
        $this->fixtures->member($user, $wedding, [WeddingPermission::ViewContent]);
        $this->fixtures->member($user, $this->fixtures->wedding(), []);
        $this->fixtures->flush();

        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/login');
        // @mago-expect lint:no-literal-password
        $this->client->submitForm('Anmelden', ['_username' => $user->getEmail(), '_password' => 'x']);
        $this->client->followRedirect();
        self::assertSelectorExists('.alert');

        $this->client->submitForm('Anmelden', [
            '_username' => strtoupper($user->getEmail()),
            '_password' => self::PASSWORD,
        ]);
        self::assertResponseRedirects('/admin');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Anna & Lukas');
    }
}
