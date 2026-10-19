<?php

declare(strict_types=1);

namespace App\Media\Entity;

use App\Media\Repository\VideoProcessingRecordRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Messwerte einer Videoumwandlung – Grundlage für Zeitprognosen.
 *
 * Bewusst anonym und ohne Fremdschlüssel: keine Gäste, Hochzeiten oder Inhalte, nur Technik.
 * Die Einträge überdauern daher auch das Löschen einer Hochzeit.
 */
#[ORM\Entity(repositoryClass: VideoProcessingRecordRepository::class)]
#[ORM\Table(name: 'video_processing_record')]
#[ORM\Index(name: 'idx_vpr_success_created', columns: ['succeeded', 'created_at'])]
class VideoProcessingRecord
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    // Bewusst flacher Datensatz für einfache Auswertung (ein Feld je Messwert).
    // @mago-expect lint:excessive-parameter-list
    public function __construct(
        /** Länge des Eingangsvideos. */
        #[ORM\Column]
        private float $inputSeconds,
        #[ORM\Column(type: 'bigint')]
        private int $inputBytes,
        #[ORM\Column]
        private int $inputWidth,
        #[ORM\Column]
        private int $inputHeight,
        #[ORM\Column(length: 32)]
        private string $inputCodec,
        /** Reine Umwandlungszeit (ffmpeg inkl. Standbild). */
        #[ORM\Column]
        private int $processingMs,
        /** Zeit vom Abschluss des Uploads bis zum Start im Worker. */
        #[ORM\Column]
        private int $queueWaitMs,
        #[ORM\Column]
        private bool $succeeded,
        #[ORM\Column(type: 'bigint', nullable: true)]
        private ?int $outputBytes = null,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $failureReason = null,
        #[ORM\Column]
        private int $threads = 0,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new DateTimeImmutable();
        $this->failureReason = null === $failureReason ? null : mb_substr($failureReason, 0, 255);
    }

    public function getInputSeconds(): float
    {
        return $this->inputSeconds;
    }

    public function getInputBytes(): int
    {
        return $this->inputBytes;
    }

    public function getInputWidth(): int
    {
        return $this->inputWidth;
    }

    public function getInputHeight(): int
    {
        return $this->inputHeight;
    }

    public function getInputCodec(): string
    {
        return $this->inputCodec;
    }

    public function getProcessingMs(): int
    {
        return $this->processingMs;
    }

    public function getQueueWaitMs(): int
    {
        return $this->queueWaitMs;
    }

    public function isSucceeded(): bool
    {
        return $this->succeeded;
    }

    public function getOutputBytes(): ?int
    {
        return $this->outputBytes;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getThreads(): int
    {
        return $this->threads;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
