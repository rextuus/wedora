<?php

declare(strict_types=1);

namespace App\Media\Controller;

use App\Challenge\Repository\ChallengeRepository;
use App\Media\Access\MediaUrlGenerator;
use App\Media\MediaDeleter;
use App\Media\MediaType;
use App\Media\Processing\ProcessingForecast;
use App\Media\Repository\MediaRepository;
use App\Media\Upload\UploadService;
use App\Security\ViewerResolver;
use App\Security\Voter\MediaVoter;
use App\Security\Voter\ModuleVoter;
use App\Wedding\Entity\Wedding;
use App\Wedding\ModuleType;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/h/{slug}', requirements: ['slug' => Wedding::SLUG_PATTERN])]
final class GuestMediaController extends GuestApiController
{
    public function __construct(
        private readonly MediaRepository $media,
        private readonly MediaUrlGenerator $urls,
        private readonly ViewerResolver $viewers,
        private readonly ProcessingForecast $forecast,
    ) {}

    /**
     * Eigene Beiträge mit Verarbeitungsstatus (für die Upload-Seite).
     */
    #[Route('/api/media/mine', name: 'api_media_mine', methods: ['GET'])]
    public function mine(Request $request): JsonResponse
    {
        $guest = $this->guest($request);
        $viewer = $this->viewers->fromSecurityUser($this->getUser());
        $items = [];

        foreach ($this->media->findBy(
            ['uploaderGuest' => $guest, 'deletedAt' => null],
            ['createdAt' => 'DESC'],
            50,
        ) as $media) {
            $item = UploadController::describe($media);
            $item['estimatedSeconds'] = $this->forecast->remainingSeconds($media);
            $item['thumbUrl'] = $media->isReady() ? $this->urls->generate($viewer, $media, 'image/thumb') : null;
            $item['videoUrl'] = $media->isReady() && MediaType::Video === $media->getType()
                ? $this->urls->generate($viewer, $media, 'video.mp4')
                : null;
            $items[] = $item;
        }

        return self::apiResponse(['items' => $items]);
    }

    #[Route('/api/media/{id}', name: 'api_media_delete', methods: ['DELETE'])]
    public function delete(Request $request, string $id, MediaDeleter $deleter): Response
    {
        $this->guest($request);
        $media = Uuid::isValid($id) ? $this->media->find(Uuid::fromString($id)) : null;

        if (null === $media || !$this->isGranted(MediaVoter::VIEW, $media)) {
            throw new NotFoundHttpException();
        }

        $this->denyAccessUnlessGranted(MediaVoter::DELETE, $media);
        $deleter->delete($media);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/upload', name: 'guest_upload', methods: ['GET'])]
    public function uploadPage(Request $request, ChallengeRepository $challenges, ClockInterface $clock): Response
    {
        $guest = $this->guest($request);
        $wedding = $guest->getWedding();
        $limits = $wedding->limits();

        $challenge = null;
        $challengeId = $request->query->getString('aufgabe');
        if (Uuid::isValid($challengeId)) {
            $challenge = $challenges->find(Uuid::fromString($challengeId));
            $now = DateTimeImmutable::createFromInterface($clock->now());
            if (null === $challenge || $challenge->getWedding() !== $wedding || !$challenge->acceptsAt($now)) {
                $challenge = null;
            }
        }

        $module = match (true) {
            null !== $challenge => ModuleType::Challenges,
            ModuleType::Guestbook->value === $request->query->getString('modul') => ModuleType::Guestbook,
            default => ModuleType::Gallery,
        };

        $response = $this->render('guest/upload.html.twig', [
            'guest' => $guest,
            'wedding' => $wedding,
            'active' => match ($module) {
                ModuleType::Guestbook => 'guestbook',
                ModuleType::Challenges => 'challenges',
                ModuleType::Gallery => 'gallery',
            },
            'challenge' => $challenge,
            'module' => $module,
            'camera' => $request->query->getBoolean('kamera'),
            'canUpload' => $this->isGranted(ModuleVoter::CONTRIBUTE, $wedding->module($module)),
            'maxPhoto' => UploadService::humanBytes($limits->maxPhotoBytes()),
            'maxVideo' => UploadService::humanBytes($limits->maxVideoBytes()),
            'maxSeconds' => $limits->maxVideoSeconds(),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
