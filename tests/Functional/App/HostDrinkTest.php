<?php

declare(strict_types=1);

namespace App\Tests\Functional\App;

use App\Account\WeddingPermission;
use App\Challenge\Entity\Challenge;
use App\Challenge\Repository\ChallengeRepository;
use App\Challenge\WelcomeMission;
use App\Tests\Functional\WebTestCase;
use App\Wedding\Host\HostPronoun;

/** Scherzaufgabe „Ein Drink für {Gastgeber}“: nur mit eingetragenem Gastgeber, Name aus den Einstellungen. */
final class HostDrinkTest extends WebTestCase
{
    public function testTemplateUsesTheHostAndIsNoFirstDrink(): void
    {
        $wedding = $this->fixtures->wedding();
        $user = $this->fixtures->user();
        $this->fixtures->member($user, self::fresh($wedding), [WeddingPermission::Configure]);
        $this->client->loginUser(self::fresh($user));
        $url = "/admin/h/{$wedding->getSlug()}/aufgaben";

        $this->client->request('GET', $url);
        self::assertSelectorTextNotContains('main', 'Ein Drink für', 'Ohne Gastgeber keine Vorlage.');

        self::fresh($wedding)->getPresentation()->describeHost('Wolfgang', null, HostPronoun::He);
        self::em()->flush();
        $crawler = $this->client->request('GET', $url);
        $this->client->submit($crawler->selectButton('+ Ein Drink für Wolfgang')->form());

        $challenges = self::service(ChallengeRepository::class)->forWedding(self::fresh($wedding));
        $drink =
            array_values(array_filter(
                $challenges,
                static fn(Challenge $c): bool => 'Ein Drink für Wolfgang' === $c->getTitle(),
            ))[0] ?? null;
        self::assertNotNull($drink);
        self::assertStringContainsString(
            'Gib Wolfgang einen Drink aus – für seine „super“ Arbeit',
            $drink->getDescription(),
        );
        self::assertNull(
            self::service(WelcomeMission::class)->drinkPair(self::fresh($wedding)),
            'Der Scherz-Drink ist weder First noch Last Drink.',
        );
        self::assertFalse(self::service(WelcomeMission::class)->isDrink($drink));
    }
}
