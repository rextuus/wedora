<?php

declare(strict_types=1);

namespace App\Ai\Command;

use App\Ai\AiBudget;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:ai:usage', description: 'Zeigt den Verbrauch an KI-Bildern (Kostenbremse für Gemini)')]
final readonly class AiUsageCommand
{
    public function __construct(
        private AiBudget $budget,
    ) {}

    public function __invoke(SymfonyStyle $io): int
    {
        $usage = $this->budget->usage();
        $io->definitionList(['Heute' => sprintf(
            '%d von %d',
            $usage['today'],
            $usage['dailyLimit'],
        )], ['Dieser Monat' => sprintf('%d von %d', $usage['month'], $usage['monthlyLimit'])]);
        $io->writeln(
            $this->budget->allows()
                ? 'Zeichnen ist verfügbar.'
                : '<comment>Grenze erreicht – es wird nichts mehr an Gemini geschickt.</comment>',
        );
        $io->writeln('Grenzen ändern: GEMINI_DAILY_LIMIT / GEMINI_MONTHLY_LIMIT (0 = aus).');

        return Command::SUCCESS;
    }
}
