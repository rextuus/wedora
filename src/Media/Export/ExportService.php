<?php

declare(strict_types=1);

namespace App\Media\Export;

use App\Account\Entity\User;
use App\Media\Entity\Export;
use App\Wedding\Entity\Wedding;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ExportService
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {}

    public function request(Wedding $wedding, ?User $requestedBy): Export
    {
        $export = new Export($wedding, $requestedBy, DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->em->persist($export);
        $this->em->flush();
        $this->bus->dispatch(new BuildExport($export->getId()->toRfc4122()));

        return $export;
    }
}
