<?php

declare(strict_types=1);

namespace App\Maintenance;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RunMaintenanceHandler
{
    public function __construct(
        private Maintenance $maintenance,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(RunMaintenance $message): void
    {
        $this->logger->info(
            'Aufräumen: {uploads} Uploads, {exports} Exporte, {links} Einmal-Links entfernt, {portraits} hängende Porträts beendet.',
            $this->maintenance->run(),
        );
    }
}
