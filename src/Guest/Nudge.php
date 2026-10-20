<?php

declare(strict_types=1);

namespace App\Guest;

use App\Guest\Entity\Guest;
use App\Media\Repository\MediaRepository;
use App\Wedding\NudgeMode;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Hinweis „Zu viel am Handy?“: Einstellungen für den Browser. Die Zeit in der App misst nur der Browser
 * selbst (nichts geht an den Server); für „viele Beiträge“ zählt der Server die eigenen der letzten Stunde.
 */
final readonly class Nudge
{
    public function __construct(
        private MediaRepository $media,
        private ClockInterface $clock,
    ) {}

    /**
     * @return array{mode: string, threshold: int, recent: int}
     */
    public function settingsFor(Guest $guest): array
    {
        $presentation = $guest->getWedding()->getPresentation();
        $mode = $presentation->getNudgeMode();

        return [
            'mode' => $mode->value,
            'threshold' => $presentation->getNudgeThreshold(),
            'recent' => NudgeMode::Activity === $mode ? $this->recentPosts($guest) : 0,
        ];
    }

    private function recentPosts(Guest $guest): int
    {
        $since = DateTimeImmutable::createFromInterface($this->clock->now())->modify('-1 hour');

        return (int) $this->media
            ->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.uploaderGuest = :guest')
            ->andWhere('m.deletedAt IS NULL')
            ->andWhere('m.createdAt >= :since')
            ->setParameter('guest', $guest->getId(), UuidType::NAME)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
