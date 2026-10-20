<?php

declare(strict_types=1);

namespace App\Media\Processing;

use App\Media\Image\PhotoEnhancer;
use App\Media\MediaType;
use App\Media\Repository\MediaRepository;
use App\Wedding\Repository\WeddingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Angleichen im Worker. Maßgeblich ist immer die aktuelle Einstellung der Hochzeit: an → Fassung erstellen,
 * aus → Fassung entfernen. So schadet es nicht, wenn Aufträge doppelt oder verspätet ankommen.
 */
final readonly class PhotoEnhancementHandler
{
    public function __construct(
        private MediaRepository $media,
        private WeddingRepository $weddings,
        private PhotoEnhancer $enhancer,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
    ) {}

    #[AsMessageHandler]
    public function photo(EnhancePhoto $message): void
    {
        $media = Uuid::isValid($message->mediaId) ? $this->media->find(Uuid::fromString($message->mediaId)) : null;
        if (null === $media || $media->isDeleted() || MediaType::Photo !== $media->getType() || !$media->isReady()) {
            return;
        }

        if ($media->getWedding()->getPresentation()->enhancesPhotos()) {
            $media->derivedChanged($this->enhancer->enhance($media->mediaId()));
        } else {
            $this->enhancer->remove($media->mediaId());
            $media->derivedChanged(0);
        }

        $this->em->flush();
    }

    #[AsMessageHandler]
    public function wedding(EnhanceWeddingPhotos $message): void
    {
        $wedding = Uuid::isValid($message->weddingId)
            ? $this->weddings->find(Uuid::fromString($message->weddingId))
            : null;
        if (null === $wedding) {
            return;
        }

        $photos = $this->media->findBy(['wedding' => $wedding, 'type' => MediaType::Photo, 'deletedAt' => null]);
        foreach ($photos as $photo) {
            $this->bus->dispatch(new EnhancePhoto($photo->getId()->toRfc4122()));
        }
    }
}
