<?php

declare(strict_types=1);

namespace App\Wedding\Command;

use App\Wedding\Repository\WeddingRepository;
use App\Wedding\WeddingEraser;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:wedding:delete',
    description: 'Löscht eine Hochzeit endgültig mit allen Medien, Exporten und Daten',
)]
final readonly class DeleteWeddingCommand
{
    public function __construct(
        private WeddingRepository $weddings,
        private WeddingEraser $eraser,
    ) {}

    public function __invoke(SymfonyStyle $io, #[Argument(description: 'Kürzel der Hochzeit')] string $slug): int
    {
        $wedding = $this->weddings->findOneBySlug($slug);
        if (null === $wedding) {
            $io->error('Hochzeit nicht gefunden.');

            return Command::FAILURE;
        }

        $io->warning(sprintf(
            '„%s“ wird endgültig gelöscht – inklusive aller Fotos, Videos und Exporte.',
            $wedding->getTitle(),
        ));
        if ($io->ask('Zur Bestätigung das Kürzel eingeben') !== $slug) {
            $io->note('Abgebrochen.');

            return Command::FAILURE;
        }

        $this->eraser->erase($wedding);
        $io->success('Hochzeit gelöscht.');

        return Command::SUCCESS;
    }
}
