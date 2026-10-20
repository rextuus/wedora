<?php

declare(strict_types=1);

namespace App\Tests\Integration\Maintenance;

use App\Guest\Entity\GuestReentryToken;
use App\Maintenance\RunMaintenance;
use App\Schedule;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\Fixtures;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class ScheduleTest extends IntegrationTestCase
{
    public function testMaintenanceRunsHourlyAndCleansUp(): void
    {
        self::bootKernel();
        $messages = self::service(Schedule::class)->getSchedule()->getRecurringMessages();
        self::assertCount(1, $messages);
        self::assertSame('every 1 hour', (string) $messages[0]->getTrigger());

        $em = self::service(EntityManagerInterface::class);
        [$guest] = new Fixtures(static fn(): EntityManagerInterface => $em)->guest(
            new Fixtures(static fn(): EntityManagerInterface => $em)->wedding(),
        );
        $em->persist(new GuestReentryToken($guest, str_repeat('c', 43), 'geheim', new DateTimeImmutable('-1 minute')));
        $em->flush();

        self::service(MessageBusInterface::class)->dispatch(new RunMaintenance());

        self::assertSame(0, $em->getRepository(GuestReentryToken::class)->count(['guest' => $guest]));
    }
}
