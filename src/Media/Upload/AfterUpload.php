<?php

declare(strict_types=1);

namespace App\Media\Upload;

use App\Challenge\DrinkVideoReplacement;
use App\Challenge\PortraitBuddy;
use App\Media\Entity\Media;
use App\Media\MediaType;
use App\Media\Processing\EnhancePhoto;
use App\Media\Processing\GenerateImageVariants;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Was nach einem vollständig angekommenen Upload passiert: First/Last Drink ersetzt das bisherige Video,
 * „Foto mit gezeichnetem Gast“ hakt die Zulosung ab, Fotos werden auf Wunsch angeglichen und ihre Varianten vorberechnet.
 */
final readonly class AfterUpload
{
    public function __construct(
        private DrinkVideoReplacement $drinkVideos,
        private PortraitBuddy $buddies,
        private MessageBusInterface $bus,
    ) {}

    public function handle(Media $media): void
    {
        $this->drinkVideos->replacePrevious($media);
        $this->buddies->complete($media);

        if (MediaType::Photo !== $media->getType()) {
            return;
        }

        // Angleichen berechnet danach auch die Varianten (aus der angeglichenen Fassung).
        $id = $media->getId()->toRfc4122();
        $this->bus->dispatch(
            $media->getWedding()->getPresentation()->enhancesPhotos()
                ? new EnhancePhoto($id)
                : new GenerateImageVariants($id),
        );
    }
}
