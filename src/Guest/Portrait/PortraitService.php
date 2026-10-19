<?php

declare(strict_types=1);

namespace App\Guest\Portrait;

use App\Ai\ImageGenerator;
use App\Guest\Entity\Guest;
use App\Wedding\Entity\Wedding;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Gezeichnete Gästeporträts: Selfie annehmen, Zeichnung im Hintergrund anstoßen, Porträt entfernen.
 */
final readonly class PortraitService
{
    /** Unter der Grenze des Webservers (9 MB); die App verkleinert das Foto vorher ohnehin. */
    public const int MAX_SELFIE_BYTES = 8 * 1024 * 1024;

    /** Formate, die Gemini als Vorlage annimmt. */
    public const array ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    public function __construct(
        private ImageGenerator $generator,
        private PortraitStorage $storage,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        #[Target('guest_portrait')]
        private RateLimiterFactoryInterface $limiter,
    ) {}

    public function isOfferedTo(Wedding $wedding): bool
    {
        return $wedding->arePortraitsEnabled() && $wedding->isActive() && $this->generator->isAvailable();
    }

    /**
     * @throws PortraitRejected
     */
    public function request(Guest $guest, UploadedFile $selfie): void
    {
        if (!$this->isOfferedTo($guest->getWedding())) {
            throw new PortraitRejected('Porträts sind für diese Hochzeit nicht eingeschaltet.');
        }

        if (PortraitStatus::Pending === $guest->getPortraitStatus()) {
            throw new PortraitRejected('Dein Porträt wird gerade gezeichnet.');
        }

        if (!$selfie->isValid() || (int) $selfie->getSize() > self::MAX_SELFIE_BYTES) {
            throw new PortraitRejected('Das Foto ist zu groß oder kam nicht vollständig an.');
        }

        // Format am Inhalt erkennen, nicht an Dateiname oder Browser-Angabe.
        $mime = mime_content_type($selfie->getPathname());
        if (!in_array($mime, self::ACCEPTED_TYPES, true)) {
            throw new PortraitRejected('Bitte ein Foto (JPEG, PNG, WebP oder HEIC) verwenden.');
        }

        if (!$this->limiter->create($guest->getId()->toRfc4122())->consume()->isAccepted()) {
            throw new PortraitRejected('Für heute sind genug Porträts gezeichnet. Morgen geht es wieder.');
        }

        $this->storage->keepSelfie($guest, $selfie->getPathname());
        $guest->portraitRequested($this->now());
        $this->em->flush();

        $this->bus->dispatch(new CreatePortrait($guest->getId()->toRfc4122()));
    }

    public function remove(Guest $guest): void
    {
        $this->storage->deleteFor($guest);
        $guest->portraitRemoved();
        $this->em->flush();
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
