<?php

declare(strict_types=1);

namespace App\Tests\Functional\Guest;

use App\Ai\Entity\AiImageUsage;
use App\Guest\Portrait\PortraitStatus;
use App\Guest\Portrait\PortraitStorage;
use App\Maintenance\Maintenance;
use App\Tests\Functional\WebTestCase;
use App\Tests\Support\FakeGemini;
use DateTimeImmutable;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Notbremsen rund ums Zeichnen: Kostenbremse und hängengebliebene Aufträge. */
final class PortraitSafetyTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeGemini::reset();
    }

    protected function tearDown(): void
    {
        $root = $_SERVER['MEDIA_ROOT'] ?? null;
        new Filesystem()->remove(is_string($root) ? $root : '/tmp/wedora-test-media');
        parent::tearDown();
    }

    public function testDailyBudgetStopsAllGenerationsAcrossWeddings(): void
    {
        $wedding = $this->fixtures->wedding();
        self::fresh($wedding)->enablePortraits(true);
        $other = $this->fixtures->wedding();
        for ($i = 0; $i < 50; ++$i) {
            self::em()->persist(new AiImageUsage('portrait', self::fresh($other), new DateTimeImmutable()));
        }

        self::em()->flush();
        [$guest, $secret] = $this->fixtures->guest(self::fresh($wedding));
        $this->actAsGuest($guest, $secret);

        $this->client->request('GET', "/h/{$wedding->getSlug()}/portraet");
        self::assertSelectorTextContains('.notice', 'für heute leider ausgeschöpft');
        self::assertSelectorNotExists('form[enctype]');

        // Das Formular fehlt absichtlich – Token von einer anderen Gastseite.
        $token = (string) $this->client
            ->request('GET', "/h/{$wedding->getSlug()}/gaestebuch")
            ->filter('input[name="_token"]')
            ->first()
            ->attr('value');
        $copy = sys_get_temp_dir() . '/selfie-' . bin2hex(random_bytes(4));
        copy(__DIR__ . '/../../Fixtures/phase0.jpg', $copy);
        $this->client->request(
            'POST',
            "/h/{$wedding->getSlug()}/portraet",
            [
                '_token' => $token,
                'consent' => '1',
                'question' => 'origin',
                'answer' => 'family',
            ],
            ['selfie' => new UploadedFile($copy, 'selfie.jpg', null, null, true)],
        );
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], FakeGemini::$requests, 'Nichts geht an Gemini.');
        self::assertNull(self::fresh($guest)->getPortraitStatus());
    }

    public function testStuckPortraitTurnsIntoRetryInsteadOfWaitingForever(): void
    {
        $wedding = $this->fixtures->wedding();
        self::fresh($wedding)->enablePortraits(true);
        [$guest, $secret] = $this->fixtures->guest(self::fresh($wedding));
        [$other] = $this->fixtures->guest(self::fresh($wedding), 'Paul');
        $storage = self::service(PortraitStorage::class);
        foreach ([$guest, $other] as $g) {
            self::fresh($g)->portraitRequested(new DateTimeImmutable('-5 minutes'));
            $storage->keepSelfie(self::fresh($g), __DIR__ . '/../../Fixtures/phase0.jpg');
        }

        [$fresh] = $this->fixtures->guest(self::fresh($wedding), 'Lea');
        self::fresh($fresh)->portraitRequested(new DateTimeImmutable('-1 minute'));
        self::em()->flush();

        $this->actAsGuest($guest, $secret);
        $this->client->request('GET', "/h/{$wedding->getSlug()}");
        self::assertSelectorTextContains('.portrait__note', 'Nochmal versuchen');
        self::assertSame(PortraitStatus::Failed, self::fresh($guest)->getPortraitStatus());
        self::assertFileDoesNotExist($storage->selfiePath(self::fresh($guest)));

        // Hängende Porträts anderer Gäste beendet das stündliche Aufräumen; frische bleiben.
        self::assertSame(1, self::service(Maintenance::class)->run()['portraits']);
        self::assertSame(PortraitStatus::Failed, self::fresh($other)->getPortraitStatus());
        self::assertFileDoesNotExist($storage->selfiePath(self::fresh($other)));
        self::assertSame(PortraitStatus::Pending, self::fresh($fresh)->getPortraitStatus());
    }

    public function testPersonalLimitNamesTheTimeWhenItWorksAgain(): void
    {
        $wedding = $this->fixtures->wedding();
        self::fresh($wedding)->enablePortraits(true);
        self::em()->flush();
        [$guest, $secret] = $this->fixtures->guest(self::fresh($wedding));
        $this->actAsGuest($guest, $secret);

        for ($i = 0; $i < 6; ++$i) {
            $token = (string) $this->client
                ->request('GET', "/h/{$wedding->getSlug()}/portraet")
                ->filter('form[enctype] input[name="_token"]')
                ->attr('value');
            $copy = sys_get_temp_dir() . '/selfie-' . bin2hex(random_bytes(4));
            copy(__DIR__ . '/../../Fixtures/phase0.jpg', $copy);
            $this->client->request(
                'POST',
                "/h/{$wedding->getSlug()}/portraet",
                [
                    '_token' => $token,
                    'consent' => '1',
                    'question' => 'origin',
                    'answer' => 'family',
                ],
                ['selfie' => new UploadedFile($copy, 'selfie.jpg', null, null, true)],
            );
        }

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.notice--error', 'Du hast schon 5 Porträts zeichnen lassen');
        self::assertSelectorTextContains('.notice--error', 'Uhr kannst du wieder');
        self::assertCount(5, FakeGemini::$requests);
    }
}
