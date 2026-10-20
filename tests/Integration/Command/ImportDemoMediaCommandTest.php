<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Challenge\Entity\Challenge;
use App\Challenge\Repository\ChallengeRepository;
use App\Demo\DemoSeeder;
use App\Demo\SeedDemoCommand;
use App\Guest\Entity\Guest;
use App\Guestbook\Entity\GuestbookEntry;
use App\Media\Entity\Media;
use App\Media\Repository\MediaRepository;
use App\Poll\CouplePoll;
use App\Poll\Repository\PollQuestionRepository;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\Fixtures;
use App\Wedding\Repository\WeddingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class ImportDemoMediaCommandTest extends IntegrationTestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        $root = $_SERVER['MEDIA_ROOT'] ?? null;
        new Filesystem()->remove([$this->dir, is_string($root) ? $root : '/tmp/wedora-test-media']);
        parent::tearDown();
    }

    public function testImportsPhotosAsDemoGuestsThroughTheUploadPipeline(): void
    {
        self::bootKernel();
        $em = self::service(EntityManagerInterface::class);
        $wedding = new Fixtures(static fn() => $em)->wedding();

        $this->dir = sys_get_temp_dir() . '/wedora-demo-' . bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->dir . '/quellen');
        $photo = __DIR__ . '/../../Fixtures/phase0.jpg';
        copy($photo, $this->dir . '/01-toast.jpg');
        file_put_contents($this->dir . '/01-toast.txt', 'Auf euch!');
        copy($photo, $this->dir . '/02-rings.jpg');
        copy($photo, $this->dir . '/quellen/nicht-importieren.jpg');
        file_put_contents($this->dir . '/notiz.md', 'wird ignoriert');

        $tester = new CommandTester(new Application(self::$kernel ?? throw new \LogicException())->find(
            'app:demo:import',
        ));
        $tester->execute(['slug' => $wedding->getSlug(), 'directory' => $this->dir, '--likes' => true]);
        $tester->assertCommandIsSuccessful();

        $media = self::service(MediaRepository::class)->findBy(['wedding' => $wedding], ['id' => 'ASC']);
        self::assertCount(2, $media);
        $uploaders = array_map(static fn(Media $m): ?string => $m->getUploaderGuest()?->getDisplayName(), $media);
        self::assertSame(array_slice(DemoSeeder::GUESTS, 0, 2), $uploaders);
        self::assertSame('Auf euch!', $media[0]->getCaption());
        self::assertNull($media[1]->getCaption());
        self::assertTrue($media[0]->isReady());

        // Erneuter Lauf legt keine weiteren Beispielgäste an.
        $guestCount = static fn(): int => $em->getRepository(Guest::class)->count(['wedding' => $wedding]);
        $before = $guestCount();
        $tester->execute(['slug' => $wedding->getSlug(), 'directory' => $this->dir]);
        self::assertSame($before, $guestCount());
        self::assertSame(count(DemoSeeder::GUESTS), $before);
    }

    public function testSeedBuildsACompleteDemoWeddingAndCanBeReset(): void
    {
        self::bootKernel();
        $this->dir = sys_get_temp_dir() . '/wedora-demo-' . bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->dir);
        copy(__DIR__ . '/../../Fixtures/phase0.jpg', $this->dir . '/01-toast.jpg');
        $slug = 'demo-' . bin2hex(random_bytes(3));

        $seed = new CommandTester(new Application(self::$kernel ?? throw new \LogicException())->find('app:demo:seed'));
        $seed->execute(['directory' => $this->dir, '--slug' => $slug, '--title' => 'Larissa & Tim']);
        $seed->assertCommandIsSuccessful();
        self::assertStringContainsString("/h/{$slug}/join?k=", $seed->getDisplay());

        $wedding = self::service(WeddingRepository::class)->findOneBySlug($slug);
        self::assertNotNull($wedding);
        self::assertSame('Larissa & Tim', $wedding->getTitle());
        self::assertTrue($wedding->arePortraitsEnabled());
        self::assertSame(
            ['First Drink', 'Last Drink', 'Wer von beiden?'],
            array_map(
                static fn(Challenge $c): string => $c->getTitle(),
                self::service(ChallengeRepository::class)->forWedding($wedding),
            ),
        );
        self::assertCount(1, self::service(MediaRepository::class)->findBy(['wedding' => $wedding]));
        self::assertTrue(self::service(CouplePoll::class)->isVisibleIn($wedding));
        $questions = self::service(PollQuestionRepository::class)->forWedding($wedding);
        self::assertCount(6, $questions);
        $em = self::service(EntityManagerInterface::class);
        self::assertSame(
            count(SeedDemoCommand::GUESTBOOK),
            $em->getRepository(GuestbookEntry::class)->count(['author' => $em->getRepository(Guest::class)->findBy([
                'wedding' => $wedding,
            ])]),
        );

        // Ohne --reset bleibt die vorhandene Hochzeit unangetastet.
        self::assertSame(1, $seed->execute(['directory' => $this->dir, '--slug' => $slug]));

        $seed->execute(['directory' => $this->dir, '--slug' => $slug, '--reset' => true]);
        $seed->assertCommandIsSuccessful();
        $em->clear();
        $fresh = self::service(WeddingRepository::class)->findOneBySlug($slug);
        self::assertNotNull($fresh);
        self::assertFalse($fresh->getId()->equals($wedding->getId()));
        self::assertCount(1, self::service(MediaRepository::class)->findBy(['wedding' => $fresh]));
    }
}
