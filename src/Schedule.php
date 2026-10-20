<?php

declare(strict_types=1);

namespace App;

use App\Maintenance\RunMaintenance;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Wiederkehrende Aufgaben; der Worker verarbeitet sie mit (`messenger:consume async scheduler_default`).
 */
#[AsSchedule]
final readonly class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {}

    public function getSchedule(): SymfonySchedule
    {
        return new SymfonySchedule()
            ->stateful($this->cache) // verpasste Läufe nach einem Neustart nachholen …
            ->processOnlyLastMissedRun(true) // … aber nur den letzten
            ->add(RecurringMessage::every('1 hour', new RunMaintenance()));
    }
}
