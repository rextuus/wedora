<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ai;

use App\Ai\AiBudget;
use App\Ai\Entity\AiImageUsage;
use App\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\MockClock;

final class AiBudgetTest extends IntegrationTestCase
{
    public function testDailyAndMonthlyLimitsCountTheRightPeriods(): void
    {
        self::bootKernel();
        $em = self::service(EntityManagerInterface::class);
        $now = new DateTimeImmutable('2026-10-20 14:00:00');
        foreach (['2026-10-20 09:00', '2026-10-19 22:00', '2026-10-01 00:00', '2026-09-30 23:59'] as $at) {
            $em->persist(new AiImageUsage('portrait', null, new DateTimeImmutable($at)));
        }

        $em->flush();

        $budget = new AiBudget($em, new MockClock($now), 2, 3);
        self::assertSame(
            ['today' => 1, 'month' => 3, 'dailyLimit' => 2, 'monthlyLimit' => 3, 'configured' => false],
            $budget->usage(),
        );
        self::assertFalse($budget->allows(), 'Monatsgrenze erreicht.');

        self::assertTrue(new AiBudget($em, new MockClock($now), 2, 10)->allows());
        $budget = new AiBudget($em, new MockClock($now), 2, 10);
        $budget->record('portrait', null);
        self::assertFalse($budget->allows(), 'Tagesgrenze erreicht.');
        self::assertFalse(new AiBudget($em, new MockClock($now), 0, 10)->allows(), 'Tageslimit 0 = Notaus.');
    }
}
