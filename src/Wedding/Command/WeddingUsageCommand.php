<?php

declare(strict_types=1);

namespace App\Wedding\Command;

use App\Media\Upload\StorageUsage;
use App\Media\Upload\UploadService;
use App\Wedding\Repository\WeddingRepository;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:wedding:usage', description: 'Zeigt Speicherverbrauch und Limits einer Hochzeit')]
final readonly class WeddingUsageCommand
{
    public function __construct(
        private WeddingRepository $weddings,
        private StorageUsage $usage,
    ) {}

    public function __invoke(SymfonyStyle $io, #[Argument(description: 'Kürzel der Hochzeit')] string $slug): int
    {
        $wedding = $this->weddings->findOneBySlug($slug);
        if (null === $wedding) {
            $io->error('Hochzeit nicht gefunden.');

            return Command::FAILURE;
        }

        $used = $this->usage->forWedding($wedding, new DateTimeImmutable());
        $limits = $wedding->limits();
        $guestQuota = $limits->guestQuotaBytes();
        $io->definitionList(
            ['Belegt (inkl. reservierter Uploads)' => UploadService::humanBytes($used)],
            ['Kontingent' => UploadService::humanBytes($limits->storageQuotaBytes())],
            ['Auslastung' => sprintf('%.1f %%', (100 * $used) / max(1, $limits->storageQuotaBytes()))],
            ['Pro Gast' => null === $guestQuota ? 'unbegrenzt' : UploadService::humanBytes($guestQuota)],
            ['Video max.' => sprintf(
                '%d s / %s',
                $limits->maxVideoSeconds(),
                UploadService::humanBytes($limits->maxVideoBytes()),
            )],
            ['Foto max.' => UploadService::humanBytes($limits->maxPhotoBytes())],
        );

        return Command::SUCCESS;
    }
}
