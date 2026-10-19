<?php

declare(strict_types=1);

namespace App\Social;

use App\Guest\Entity\Guest;
use App\Media\Entity\Media;
use App\Social\Entity\MediaLike;
use App\Social\Repository\MediaLikeRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class LikeService
{
    public function __construct(
        private MediaLikeRepository $likes,
        private EntityManagerInterface $em,
    ) {}

    /** Schaltet das Like um; liefert, ob der Gast danach „gefällt mir“ gesetzt hat. */
    public function toggle(Guest $guest, Media $media): bool
    {
        $existing = $this->likes->findOneBy(['media' => $media, 'guest' => $guest]);
        if (null !== $existing) {
            $this->em->remove($existing);
            $this->em->flush();

            return false;
        }

        $this->em->persist(new MediaLike($media, $guest));
        $this->em->flush();

        return true;
    }

    /**
     * @param list<Media> $media
     *
     * @return array<string, int> Anzahl je Medien-ID
     */
    public function counts(array $media): array
    {
        if ([] === $media) {
            return [];
        }

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT media_id, COUNT(*) AS n FROM media_like WHERE media_id IN (?) GROUP BY media_id',
            [array_map(static fn(Media $m): string => $m->getId()->toBinary(), $media)],
            [ArrayParameterType::BINARY],
        );

        $counts = [];
        foreach ($rows as $row) {
            if (is_string($row['media_id']) && is_numeric($row['n'])) {
                $counts[Uuid::fromBinary($row['media_id'])->toRfc4122()] = (int) $row['n'];
            }
        }

        return $counts;
    }

    /**
     * @param list<Media> $media
     *
     * @return array<string, true> IDs, die der Gast mag
     */
    public function likedBy(Guest $guest, array $media): array
    {
        if ([] === $media) {
            return [];
        }

        $ids = $this->em->getConnection()->fetchFirstColumn(
            'SELECT media_id FROM media_like WHERE guest_id = ? AND media_id IN (?)',
            [$guest->getId()->toBinary(), array_map(static fn(Media $m): string => $m->getId()->toBinary(), $media)],
            [ParameterType::BINARY, ArrayParameterType::BINARY],
        );

        $liked = [];
        foreach (array_filter($ids, is_string(...)) as $id) {
            $liked[Uuid::fromBinary($id)->toRfc4122()] = true;
        }

        return $liked;
    }
}
