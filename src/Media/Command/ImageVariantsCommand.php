<?php

declare(strict_types=1);

namespace App\Media\Command;

use App\Media\Image\ImagePreset;
use App\Media\Image\ImageVariants;
use App\Media\MediaId;
use App\Media\Processing\GenerateImageVariants;
use App\Media\ProcessingStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Für vorhandene Medien ohne vorberechnete Varianten (z. B. nach einem Update): Aufträge an den Worker.
 * Läuft bei jedem `make prod-up` mit; schon Vorhandenes wird übersprungen.
 */
#[AsCommand(name: 'app:media:variants', description: 'Fehlende Bild-Varianten im Hintergrund berechnen lassen')]
final readonly class ImageVariantsCommand
{
    public function __construct(
        private EntityManagerInterface $em,
        private ImageVariants $variants,
        private MessageBusInterface $bus,
    ) {}

    public function __invoke(SymfonyStyle $io): int
    {
        $ids = $this->em->getConnection()->fetchFirstColumn('SELECT id FROM media WHERE deleted_at IS NULL AND processing_status = :ready', [
            'ready' => ProcessingStatus::Ready->value,
        ]);

        $queued = 0;
        foreach (array_filter($ids, is_string(...)) as $binary) {
            $id = Uuid::fromBinary($binary)->toRfc4122();
            if (!$this->variants->has(new MediaId($id), ImagePreset::Thumb)) {
                $this->bus->dispatch(new GenerateImageVariants($id));
                ++$queued;
            }
        }

        $io->success(sprintf('%d von %d Medien zur Berechnung eingereiht.', $queued, count($ids)));

        return Command::SUCCESS;
    }
}
