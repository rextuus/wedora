<?php

declare(strict_types=1);

namespace App\Media\Command;

use App\Maintenance\Maintenance;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Aufräumen von Hand; läuft sonst stündlich automatisch im Worker (siehe App\Schedule).
 */
#[AsCommand(name: 'app:media:cleanup', description: 'Entfernt abgelaufene Uploads, Exporte und Einmal-Links')]
final readonly class CleanupCommand
{
    public function __construct(
        private Maintenance $maintenance,
    ) {}

    public function __invoke(SymfonyStyle $io): int
    {
        $removed = $this->maintenance->run();
        $io->success(sprintf(
            '%d Uploads, %d Exporte und %d Einmal-Links entfernt, %d hängende Porträts beendet.',
            $removed['uploads'],
            $removed['exports'],
            $removed['links'],
            $removed['portraits'],
        ));

        return Command::SUCCESS;
    }
}
