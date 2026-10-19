<?php

declare(strict_types=1);

namespace App\Demo;

use App\Wedding\Repository\WeddingRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Spielt Beispielfotos und -videos als erfundene Gäste ein, über denselben Upload-Weg wie die App
 * (Formatprüfung, Kontingente, Video-Verarbeitung im Worker). Für Vorführungen und Entwicklung.
 */
#[AsCommand(name: 'app:demo:import', description: 'Spielt Beispielmedien als erfundene Gäste in eine Hochzeit ein')]
final readonly class ImportDemoMediaCommand
{
    public function __construct(
        private WeddingRepository $weddings,
        private DemoSeeder $seeder,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Kürzel der Hochzeit')]
        string $slug,
        #[Argument(description: 'Ordner mit JPEG/PNG/WebP/MP4/MOV-Dateien')]
        string $directory,
        #[Option(description: 'Zufällige Likes der Beispielgäste vergeben')]
        bool $likes = false,
    ): int {
        $wedding = $this->weddings->findOneBySlug($slug);
        if (null === $wedding || !is_dir($directory)) {
            $io->error(null === $wedding ? 'Hochzeit nicht gefunden.' : 'Ordner nicht gefunden.');

            return Command::FAILURE;
        }

        $imported = $this->seeder->importDirectory($wedding, $directory, $likes, $io->writeln(...));
        $io->success(sprintf('%d Medien eingespielt. Videos verarbeitet der Worker.', count($imported)));

        return Command::SUCCESS;
    }
}
