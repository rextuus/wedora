<?php

declare(strict_types=1);

namespace App\Guest\Portrait;

use App\Ai\ImageGenerator;
use App\Ai\ImageInput;
use App\Guest\Entity\Guest;
use App\Wedding\CoupleNames;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Lässt das Selfie zeichnen und löscht es danach in jedem Fall. Fehler werden nicht wiederholt
 * (das Selfie ist dann schon weg); der Gast sieht „hat nicht geklappt“ und kann es erneut versuchen.
 */
#[AsMessageHandler]
final readonly class CreatePortraitHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private ImageGenerator $generator,
        private PortraitStorage $storage,
        private PortraitRenderer $renderer,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(CreatePortrait $message): void
    {
        $guest = $this->em->find(Guest::class, Uuid::fromString($message->guestId));
        if (null === $guest || PortraitStatus::Pending !== $guest->getPortraitStatus()) {
            return;
        }

        $selfie = $this->storage->selfiePath($guest);
        $output = $this->storage->portraitPath($guest) . '.part.png';

        try {
            $mime = is_file($selfie) ? mime_content_type($selfie) : false;
            if (false === $mime) {
                throw new RuntimeException('Selfie nicht mehr vorhanden.');
            }

            $style = $guest->getPortraitStyle();
            $drawing = $this->generator->generate(
                $style->prompt($guest->getPortraitGadget(), CoupleNames::of($guest->getWedding())),
                [ImageInput::fromFile($selfie, $mime)],
            );
            $this->renderer->render($drawing->bytes, $output, $style->isLineArt());
            $this->storage->savePortrait($guest, $output);
            $guest->portraitDrawn($this->now());
        } catch (RuntimeException $e) { // inkl. ImageGenerationFailed
            new Filesystem()->remove($output);
            $this->logger->warning('Porträt konnte nicht erstellt werden: {reason}', [
                'reason' => $e->getMessage(),
                'guest' => $message->guestId,
            ]);
            $guest->portraitFailed($this->now());
        } finally {
            $this->storage->deleteSelfie($guest);
        }

        $this->em->flush();
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
