<?php

declare(strict_types=1);

namespace App\Media\Export;

use App\Media\ExportStatus;
use App\Media\Repository\ExportRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;
use Throwable;

#[AsMessageHandler]
final readonly class BuildExportHandler
{
    public function __construct(
        private ExportRepository $exports,
        private ExportBuilder $builder,
        private ExportFiles $files,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(BuildExport $message): void
    {
        $export = Uuid::isValid($message->exportId) ? $this->exports->find(Uuid::fromString($message->exportId)) : null;
        if (null === $export || ExportStatus::Pending !== $export->getStatus()) {
            return;
        }

        try {
            $this->builder->build($export);
        } catch (Throwable $e) {
            $this->logger->error('Export fehlgeschlagen.', [
                'export' => $message->exportId,
                'error' => $e->getMessage(),
            ]);
            $this->files->delete($export);
            $export->markFailed(
                'Der Export ist fehlgeschlagen.',
                DateTimeImmutable::createFromInterface($this->clock->now()),
            );
        }

        $this->em->flush();
    }
}
