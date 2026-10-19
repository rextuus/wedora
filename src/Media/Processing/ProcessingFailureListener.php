<?php

declare(strict_types=1);

namespace App\Media\Processing;

use App\Media\Repository\MediaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Uid\Uuid;

/**
 * Nach dem letzten Wiederholungsversuch: Medium als fehlgeschlagen markieren,
 * damit der Gast eine Rückmeldung bekommt statt ewig „wird verarbeitet“.
 */
#[AsEventListener]
final readonly class ProcessingFailureListener
{
    public function __construct(
        private MediaRepository $media,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof ProcessVideo || $event->willRetry() || !Uuid::isValid($message->mediaId)) {
            return;
        }

        $media = $this->media->find(Uuid::fromString($message->mediaId));
        if (null === $media) {
            return;
        }

        $this->logger->error('Videoverarbeitung endgültig fehlgeschlagen.', [
            'media' => $message->mediaId,
            'error' => $event->getThrowable()->getMessage(),
        ]);
        $media->markFailed('Die Verarbeitung ist fehlgeschlagen. Bitte versuche es erneut.');
        $this->em->flush();
    }
}
