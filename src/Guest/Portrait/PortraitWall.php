<?php

declare(strict_types=1);

namespace App\Guest\Portrait;

use App\Guest\Entity\Guest;
use App\Wedding\Entity\Wedding;
use Doctrine\ORM\EntityManagerInterface;
use SortDirection;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Gästewand „Wer ist alles da?“: gezeichnete Porträts, die Gäste selbst freigegeben haben.
 *
 * Die Porträts der anderen sieht nur, wer sein eigenes ebenfalls zeigt (wie bei den Drink-Videos).
 * Das gilt überall, auch für die kleinen Bilder am Namen und die Bildadresse selbst.
 * Brautpaar und Verwaltung sehen keine Porträts (ADR 0005).
 */
final readonly class PortraitWall
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /** Gibt es die Wand in dieser Hochzeit? (Porträts eingeschaltet) */
    public function isOfferedIn(Wedding $wedding): bool
    {
        return $wedding->arePortraitsEnabled();
    }

    public function canSee(Guest $viewer, Guest $owner): bool
    {
        if ($owner->getWedding() !== $viewer->getWedding() || !$owner->hasPortrait()) {
            return false;
        }

        if ($owner === $viewer) {
            return true;
        }

        return self::isOnWall($owner) && self::isOnWall($viewer) && $this->isOfferedIn($owner->getWedding());
    }

    /**
     * Alle Gäste mit freigegebenem Porträt, nach Name.
     *
     * @return list<Guest>
     */
    public function guests(Wedding $wedding): array
    {
        /** @var list<Guest> */
        return $this->em
            ->createQueryBuilder()
            ->select('g')
            ->from(Guest::class, 'g')
            ->where('g.wedding = :wedding')
            ->andWhere('g.portraitShared = true')
            ->andWhere('g.portraitStatus = :ready')
            ->andWhere('g.blockedAt IS NULL')
            ->orderBy('g.displayName', SortDirection::Ascending)
            ->setParameter('wedding', $wedding->getId(), UuidType::NAME)
            ->setParameter('ready', PortraitStatus::Ready)
            ->getQuery()
            ->getResult();
    }

    private static function isOnWall(Guest $guest): bool
    {
        return $guest->isPortraitShared() && $guest->hasPortrait() && !$guest->isBlocked();
    }
}
