<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Media\Entity\Export;
use App\Media\Entity\Upload;
use App\Media\MediaType;
use App\Media\Repository\ExportRepository;
use App\Media\Repository\UploadRepository;
use App\Media\Upload\UploadBuffer;
use App\Media\Visibility;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\Fixtures;
use App\Wedding\ModuleType;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class CleanupCommandTest extends IntegrationTestCase
{
    public function testExpiredUploadsAndExportsAreRemovedFreshOnesKept(): void
    {
        self::bootKernel();
        $em = self::service(EntityManagerInterface::class);
        $fixtures = new Fixtures(static fn(): EntityManagerInterface => $em);
        $wedding = $fixtures->wedding();
        [$guest] = $fixtures->guest($wedding);
        $now = new DateTimeImmutable();

        $expired = new Upload(
            $guest,
            ModuleType::Gallery,
            MediaType::Photo,
            Visibility::AllGuests,
            10,
            $now->modify('-2 days'),
        );
        $fresh = new Upload($guest, ModuleType::Gallery, MediaType::Photo, Visibility::AllGuests, 10, $now);
        $oldExport = new Export($wedding, null, $now->modify('-8 days'));
        $newExport = new Export($wedding, null, $now);
        foreach ([$expired, $fresh, $oldExport, $newExport] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $buffer = self::service(UploadBuffer::class);
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        $buffer->append($expired, $stream, 10);
        self::assertFileExists($buffer->path($expired));

        $tester = new CommandTester(new Application(self::$kernel ?? throw new \LogicException())->find(
            'app:media:cleanup',
        ));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        self::assertFileDoesNotExist($buffer->path($expired));
        $em->clear();
        self::assertNull(self::service(UploadRepository::class)->find($expired->getId()));
        self::assertNotNull(self::service(UploadRepository::class)->find($fresh->getId()));
        self::assertNull(self::service(ExportRepository::class)->find($oldExport->getId()));
        self::assertNotNull(self::service(ExportRepository::class)->find($newExport->getId()));
    }
}
