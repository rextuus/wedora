<?php

declare(strict_types=1);

namespace App\Social;

use App\Guest\Entity\Guest;
use App\Media\Entity\Media;
use App\Social\Entity\MediaReport;
use App\Social\Repository\MediaReportRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ReportService
{
    public function __construct(
        private MediaReportRepository $reports,
        private EntityManagerInterface $em,
    ) {}

    /** Mehrfaches Melden durch denselben Gast zählt einmal. */
    public function report(Guest $guest, Media $media, ReportReason $reason, string $note = ''): MediaReport
    {
        $existing = $this->reports->findOneBy(['media' => $media, 'reporter' => $guest]);
        if (null !== $existing) {
            return $existing;
        }

        $report = new MediaReport($media, $guest, $reason, $note);
        $this->em->persist($report);
        $this->em->flush();

        return $report;
    }
}
