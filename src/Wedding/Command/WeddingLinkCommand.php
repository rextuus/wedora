<?php

declare(strict_types=1);

namespace App\Wedding\Command;

use App\Wedding\PublicLinks;
use App\Wedding\Repository\WeddingRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:wedding:link', description: 'Zeigt den QR-/Beitrittslink einer Hochzeit')]
final readonly class WeddingLinkCommand
{
    public function __construct(
        private WeddingRepository $weddings,
        private PublicLinks $links,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Kürzel der Hochzeit')]
        string $slug,
        #[Option(description: 'Nur den Link ausgeben (für Skripte)')]
        bool $plain = false,
    ): int {
        $wedding = $this->weddings->findOneBySlug($slug);
        if (null === $wedding) {
            $io->error('Hochzeit nicht gefunden.');

            return Command::FAILURE;
        }

        $link = $this->links->join($wedding);
        if ($plain) {
            $io->writeln($link);

            return Command::SUCCESS;
        }

        $io->definitionList(['QR-/Beitrittslink' => $link]);
        if (!$wedding->access()->isOpenLinkEnabled()) {
            $io->warning('Der offene Link ist für diese Hochzeit ausgeschaltet (Verwaltung → Zugang).');
        }

        return Command::SUCCESS;
    }
}
