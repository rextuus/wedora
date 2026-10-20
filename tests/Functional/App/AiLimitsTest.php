<?php

declare(strict_types=1);

namespace App\Tests\Functional\App;

use App\Account\WeddingPermission;
use App\Ai\AiBudget;
use App\Tests\Functional\WebTestCase;

final class AiLimitsTest extends WebTestCase
{
    public function testPlatformAdminSetsTheLimitsAndTheyApplyImmediately(): void
    {
        $admin = $this->fixtures->user(platformAdmin: true);
        $this->client->loginUser(self::fresh($admin));

        $crawler = $this->client->request('GET', '/admin');
        self::assertSelectorTextContains('main', 'Kostenbremse');
        self::assertSelectorTextContains('main', 'Standardwerte aus der Server-Konfiguration');
        $form = $crawler->selectButton('Grenzen speichern')->form(['daily_limit' => '7', 'monthly_limit' => '40']);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin');

        $usage = self::service(AiBudget::class)->usage();
        self::assertSame([7, 40, true], [$usage['dailyLimit'], $usage['monthlyLimit'], $usage['configured']]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'heute 0 von 7');

        // Notaus über die Verwaltung.
        $crawler = $this->client->request('GET', '/admin');
        $this->client->submit($crawler
            ->selectButton('Grenzen speichern')
            ->form(['daily_limit' => '0', 'monthly_limit' => '40']));
        self::assertFalse(self::service(AiBudget::class)->allows());

        // Unsinnige Werte werden abgelehnt, die alten bleiben.
        $crawler = $this->client->request('GET', '/admin');
        $this->client->submit($crawler
            ->selectButton('Grenzen speichern')
            ->form(['daily_limit' => '99999', 'monthly_limit' => '40']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.notice--error', '0 bis 10000');
        self::assertSame(0, self::service(AiBudget::class)->usage()['dailyLimit']);
    }

    public function testCoupleAccountsCannotChangeTheLimits(): void
    {
        $wedding = $this->fixtures->wedding();
        $user = $this->fixtures->user();
        $this->fixtures->member($user, self::fresh($wedding), [WeddingPermission::Configure]);
        $this->client->loginUser(self::fresh($user));

        $this->client->request('GET', '/admin');
        self::assertSelectorTextNotContains('main', 'Grenzen speichern');
        $this->client->request('POST', '/admin/ki-grenzen', ['daily_limit' => '9999', 'monthly_limit' => '9999']);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse(self::service(AiBudget::class)->usage()['configured']);
    }
}
