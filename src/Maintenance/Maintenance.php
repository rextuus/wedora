<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Guest\AppReentry;
use App\Guest\Portrait\PortraitService;
use App\Media\Entity\Export;
use App\Media\Entity\Upload;
use App\Media\Export\ExportFiles;
use App\Media\Repository\ExportRepository;
use App\Media\Repository\UploadRepository;
use App\Media\Upload\UploadBuffer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Regelmäßiges Aufräumen: abgelaufene Uploads (samt Puffer-Stücken), abgelaufene Exporte (samt ZIP)
 * verbrauchte oder abgelaufene Einmal-Links und hängengebliebene Porträts. Läuft stündlich im Worker (Scheduler) und per
 * `app:media:cleanup` von Hand.
 */
final readonly class Maintenance
{
    public function __construct(
        private UploadRepository $uploads,
        private ExportRepository $exports,
        private UploadBuffer $buffer,
        private ExportFiles $exportFiles,
        private AppReentry $reentry,
        private PortraitService $portraits,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {}

    /**
     * @return array{uploads: int, exports: int, links: int, portraits: int} Anzahl entfernter bzw. beendeter Einträge
     */
    public function run(): array
    {
        $now = DateTimeImmutable::createFromInterface($this->clock->now());

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
        }

        $this->em->flush();

        return [
            'uploads' => count($expiredUploads),
            'exports' => count($expiredExports),
            'links' => $this->reentry->purge(),
            'portraits' => $this->portraits->expireAllStale(),
        ];
    }
}
