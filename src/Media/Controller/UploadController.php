<?php

declare(strict_types=1);

namespace App\Media\Controller;

use App\Challenge\ChallengeUploadRules;
use App\Challenge\DrinkVideoReplacement;
use App\Challenge\PortraitBuddy;
use App\Challenge\Repository\ChallengeRepository;
use App\Media\Entity\Media;
use App\Media\Entity\Upload;
use App\Media\MediaType;
use App\Media\Processing\ProcessingForecast;
use App\Media\Repository\UploadRepository;
use App\Media\Upload\UploadOffsetMismatch;
use App\Media\Upload\UploadRejected;
use App\Media\Upload\UploadRequest;
use App\Media\Upload\UploadService;
use App\Media\Visibility;
use App\Security\Voter\ModuleVoter;
use App\Wedding\Entity\Wedding;
use App\Wedding\ModuleType;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Fortsetzbarer Upload in Stücken (angelehnt an das tus-Protokoll):
 *   POST   …/uploads            Upload ankündigen (Typ, Größe, Sichtbarkeit) → id, Stückgröße
 *   HEAD   …/uploads/{id}       aktueller Stand (Upload-Offset) zum Fortsetzen
 *   PATCH  …/uploads/{id}       nächstes Stück ab Upload-Offset
 *   DELETE …/uploads/{id}       abbrechen
 */
#[Route('/h/{slug}/api/uploads', requirements: ['slug' => Wedding::SLUG_PATTERN])]
final class UploadController extends GuestApiController
{
    public function __construct(
        private readonly UploadService $uploads,
        private readonly UploadRepository $repository,
        private readonly ProcessingForecast $forecast,
        private readonly ChallengeRepository $challenges,
    ) {}

    #[Route('', name: 'api_upload_start', methods: ['POST'])]
    public function start(Request $request, ChallengeUploadRules $rules): JsonResponse
    {
        $guest = $this->guest($request);
        $parsed = self::parseStart($request);
        if (null === $parsed) {
            return self::error('Ungültige Anfrage.', Response::HTTP_BAD_REQUEST);
        }

        $challenge = null;
        if (null !== $parsed->challengeId) {
            $challenge = Uuid::isValid($parsed->challengeId)
                ? $this->challenges->find(Uuid::fromString($parsed->challengeId))
                : null;
            if (null === $challenge || $challenge->getWedding() !== $guest->getWedding()) {
                return self::error('Aufgabe nicht gefunden.', Response::HTTP_NOT_FOUND);
            }

            // z. B. „Foto mit gezeichnetem Gast“ nur mit zugelostem Partner – sonst ließen sich die Regeln umgehen.
            $rejection = $rules->rejection($guest, $challenge);
            if (null !== $rejection) {
                return self::error($rejection, Response::HTTP_CONFLICT);
            }
        }

        $module = null === $challenge ? $parsed->module : ModuleType::Challenges;
        if (!$this->isGranted(ModuleVoter::CONTRIBUTE, $guest->getWedding()->module($module))) {
            return self::error('Hier können gerade keine Beiträge hochgeladen werden.', Response::HTTP_FORBIDDEN);
        }

        try {
            $upload = $this->uploads->start($guest, $parsed, $challenge);
        } catch (UploadRejected $e) {
            return self::error($e->getMessage(), $e->httpStatus);
        }

        return self::apiResponse([
            'id' => $upload->getId()->toRfc4122(),
            'offset' => 0,
            'chunkSize' => UploadService::CHUNK_BYTES,
            'expiresAt' => $upload->getExpiresAt()->format(DATE_ATOM),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_upload_status', methods: ['HEAD', 'GET'])]
    public function status(Request $request, string $id): Response
    {
        $upload = $this->ownUpload($request, $id);

        return new Response(status: Response::HTTP_NO_CONTENT, headers: [
            'Upload-Offset' => (string) $upload->getReceivedBytes(),
            'Upload-Length' => (string) $upload->getDeclaredBytes(),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    #[Route('/{id}', name: 'api_upload_append', methods: ['PATCH'])]
    public function append(
        Request $request,
        string $id,
        DrinkVideoReplacement $drinkVideos,
        PortraitBuddy $buddies,
    ): Response {
        $upload = $this->ownUpload($request, $id);
        $offset = $request->headers->get('Upload-Offset');
        if (null === $offset || !ctype_digit($offset)) {
            return self::error('Upload-Offset fehlt.', Response::HTTP_BAD_REQUEST);
        }

        $stream = $request->getContent(true);

        try {
            $media = $this->uploads->append($upload, (int) $offset, $stream);
        } catch (UploadOffsetMismatch $e) {
            return new JsonResponse(
                ['error' => 'Upload-Offset passt nicht.', 'offset' => $e->expectedOffset],
                Response::HTTP_CONFLICT,
                [
                    'Upload-Offset' => (string) $e->expectedOffset,
                ],
            );
        } catch (UploadRejected $e) {
            return self::error($e->getMessage(), $e->httpStatus);
        }

        if (null === $media) {
            return new Response(status: Response::HTTP_NO_CONTENT, headers: [
                'Upload-Offset' => (string) $upload->getReceivedBytes(),
                'Cache-Control' => 'private, no-store',
            ]);
        }

        // First/Last Drink: Das neue Video ersetzt das bisherige – erst jetzt, da es vollständig angekommen ist.
        $drinkVideos->replacePrevious($media);
        // „Foto mit gezeichnetem Gast“: hakt die offene Zulosung ab.
        $buddies->complete($media);

        $data = self::describe($media);
        $data['estimatedSeconds'] = $this->forecast->remainingSeconds($media);

        return self::apiResponse(['media' => $data], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_upload_abort', methods: ['DELETE'])]
    public function abort(Request $request, string $id): Response
    {
        $this->uploads->abort($this->ownUpload($request, $id));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    private static function parseStart(Request $request): ?UploadRequest
    {
        try {
            $payload = $request->toArray();
        } catch (JsonException) {
            return null;
        }

        $type = MediaType::tryFrom(self::stringField($payload, 'type') ?? '');
        $visibility = Visibility::tryFrom(self::stringField($payload, 'visibility') ?? Visibility::AllGuests->value);
        $module = ModuleType::tryFrom(self::stringField($payload, 'module') ?? ModuleType::Gallery->value);
        $size = self::intField($payload, 'size');

        if (null === $type || null === $visibility || null === $module || null === $size) {
            return null;
        }

        $caption = self::stringField($payload, 'caption');

        return new UploadRequest(
            $type,
            $size,
            $visibility,
            $module,
            self::stringField($payload, 'challenge'),
            $caption,
        );
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private static function intField(array $payload, string $key): ?int
    {
        return array_key_exists($key, $payload) && is_int($payload[$key]) ? $payload[$key] : null;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private static function stringField(array $payload, string $key): ?string
    {
        return array_key_exists($key, $payload) && is_string($payload[$key]) ? $payload[$key] : null;
    }

    /**
     * Fremde oder unbekannte Uploads sind nicht unterscheidbar (404).
     */
    private function ownUpload(Request $request, string $id): Upload
    {
        $guest = $this->guest($request);
        $upload = Uuid::isValid($id) ? $this->repository->find(Uuid::fromString($id)) : null;

        if (null === $upload || !$upload->getGuest()->getId()->equals($guest->getId())) {
            throw new NotFoundHttpException();
        }

        return $upload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function describe(Media $media): array
    {
        return [
            'id' => $media->getId()->toRfc4122(),
            'type' => $media->getType()->value,
            'processing' => $media->getProcessingStatus()->value,
            'publication' => $media->getPublicationStatus()->value,
            'visibility' => $media->getVisibility()->value,
            'error' => $media->getProcessingError(),
        ];
    }
}
