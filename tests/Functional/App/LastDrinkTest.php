<?php

declare(strict_types=1);

namespace App\Tests\Functional\App;

use App\Account\WeddingPermission;
use App\Challenge\Entity\Challenge;
use App\Challenge\Repository\ChallengeRepository;
use App\Tests\Functional\WebTestCase;
use DateTimeImmutable;

/** Last Drink erst spät am Abend: Vorgabe 23 Uhr am Hochzeitstag, vorher ausgegraut. */
final class LastDrinkTest extends WebTestCase
{
    public function testLastDrinkStartsAtElevenAndIsGreyedOutBefore(): void
    {
        $wedding = $this->fixtures->wedding();
        $tomorrow = new DateTimeImmutable('tomorrow');
        self::fresh($wedding)->setWeddingDate($tomorrow);
        self::em()->flush();
        $user = $this->fixtures->user();
        $this->fixtures->member($user, self::fresh($wedding), [WeddingPermission::Configure]);
        $this->client->loginUser(self::fresh($user));

        $crawler = $this->client->request('GET', "/admin/h/{$wedding->getSlug()}/aufgaben");
        $this->client->submit($crawler->selectButton('+ Last Drink')->form());
        $last =
            array_values(array_filter(
                self::service(ChallengeRepository::class)->forWedding(self::fresh($wedding)),
                static fn(Challenge $c): bool => 'Last Drink' === $c->getTitle(),
            ))[0] ?? null;
        self::assertNotNull($last);
        self::assertSame(
            $tomorrow->format('Y-m-d') . ' 23:00',
            $last->getStartsAt()?->format('Y-m-d H:i'),
            'Änderbar unter „Ab“.',
        );

        $this->client->getCookieJar()->clear();
        [$guest, $secret] = $this->fixtures->guest(self::fresh($wedding));
        $this->actAsGuest($guest, $secret);
        $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben");
        self::assertSelectorTextContains('.challenge-item.is-paused', 'Ab 23:00 Uhr');
        self::assertSelectorNotExists('.challenge-item.is-paused .button', 'Vorher kein Mitmachen.');
    }
}
