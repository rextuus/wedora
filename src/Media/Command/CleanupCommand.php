<?php

declare(strict_types=1);

namespace App\Media\Command;

use App\Media\Entity\Export;
use App\Media\Entity\Upload;
use App\Media\Export\ExportFiles;
use App\Media\Repository\ExportRepository;
use App\Media\Repository\UploadRepository;
use App\Media\Upload\UploadBuffer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Regelmäßig ausführen (z. B. stündlich per Cron/Scheduler, Phase 6).
 */
#[AsCommand(name: 'app:media:cleanup', description: 'Entfernt abgelaufene Uploads und Exporte')]
final readonly class CleanupCommand
{
    public function __construct(
        private UploadRepository $uploads,
        private ExportRepository $exports,
        private UploadBuffer $buffer,
        private ExportFiles $exportFiles,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {}

    public function __invoke(SymfonyStyle $io): int
    {
        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $uploads = 0;
        $exports = 0;

        /** @var list<Upload> $expiredUploads */
        $expiredUploads = $this->uploads
            ->createQueryBuilder('u')
            ->where('u.expiresAt <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
        foreach ($expiredUploads as $upload) {
            $this->buffer->delete($upload);
            $this->em->remove($upload);
            ++$uploads;
        }

        /** @var list<Export> $expiredExports */
        $expiredExports = $this->exports
            ->createQueryBuilder('e')
            ->where('e.expiresAt <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
        foreach ($expiredExports as $export) {
            $this->exportFiles->delete($export);
            $this->em->remove($export);
            ++$exports;
        }

        $this->em->flush();
        $io->success(sprintf('%d Uploads und %d Exporte entfernt.', $uploads, $exports));

        return Command::SUCCESS;
    }
}
