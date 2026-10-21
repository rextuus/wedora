<?php

declare(strict_types=1);

namespace App\Controller;

use App\Media\Access\MediaAccessChecker;
use App\Media\Access\MediaAccessTokenSigner;
use App\Media\Image\ImagePreset;
use App\Media\Image\ImageVariants;
use App\Media\Image\ImgproxyUrlSigner;
use App\Media\MediaId;
use App\Media\Storage\MediaFile;
use App\Media\Storage\MediaStorage;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Einziger Weg zu privaten Medien: Symfony prüft Token und Berechtigung bei jeder
 * Anfrage, danach liefert Caddy per X-Accel-Redirect aus dem privaten Speicher bzw.
 * über das interne imgproxy aus. Weder Speicher noch imgproxy sind direkt erreichbar.
 */
final readonly class ProtectedMediaController
{
    public function __construct(
        private MediaAccessTokenSigner $tokens,
        private MediaAccessChecker $accessChecker,
        private MediaStorage $storage,
        private ImgproxyUrlSigner $imgproxy,
        private ImageVariants $variants,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {}

    #[Route(
        '/media/{token}/{variant}',
        name: 'media_file',
        requirements: [
            // @mago-expect lint:no-literal-password
            'token' => '[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+',
            'variant' => 'video\.mp4|image/(?:thumb|display|tiny)',
        ],
        methods: ['GET', 'HEAD'],
    )]
    public function __invoke(#[SensitiveParameter] string $token, string $variant): Response
    {
        $access = $this->tokens->verify($token);
        if (null === $access) {
            $this->logger->info('Medienzugriff abgelehnt: Token ungültig oder abgelaufen.');

            return self::status(Response::HTTP_FORBIDDEN);
        }

        try {
            $id = new MediaId($access->mediaId);
        } catch (InvalidArgumentException) {
            return self::status(Response::HTTP_FORBIDDEN);
        }

        if (!$this->accessChecker->canView($access->subject, $id->value, $variant)) {
            $this->logger->info('Medienzugriff abgelehnt: keine Berechtigung.', ['media' => $id->value]);

            return self::status(Response::HTTP_FORBIDDEN);
        }

        $redirect = 'video.mp4' === $variant ? $this->videoRedirect($id) : $this->imageRedirect($id, $variant);

        if (null === $redirect) {
            return self::status(Response::HTTP_NOT_FOUND);
        }

        // Nur im Browser des Betrachters zwischenspeichern, und nur solange die URL gilt (Caddy übernimmt den Wert).
        $maxAge = max(0, $access->expiresAt - $this->clock->now()->getTimestamp());

        return new Response('', Response::HTTP_OK, [
            'X-Accel-Redirect' => $redirect,
            'Cache-Control' => sprintf('private, max-age=%d', $maxAge),
        ]);
    }

    private function videoRedirect(MediaId $id): ?string
    {
        if (!$this->storage->exists($id, MediaFile::Video)) {
            return null;
        }

        return '/_protected/files/' . $this->storage->relativePath($id, MediaFile::Video);
    }

    private function imageRedirect(MediaId $id, string $variant): ?string
    {
        $preset = ImagePreset::from(substr($variant, strlen('image/')));
        // Vorberechnet liefert Caddy die Datei direkt; sonst rechnet imgproxy (z. B. kurz nach dem Upload).
        if ($this->variants->has($id, $preset)) {
            return '/_protected/files/' . $this->storage->relativePath($id, $preset->file());
        }

        // Bei Videos dient das Standbild als Bildquelle; bei Fotos die angeglichene Fassung, falls vorhanden.
        $source = $this->variants->source($id);
        if (null === $source) {
            return null;
        }

        return '/_protected/imgproxy'
        . $this->imgproxy->sign('local:///' . $this->storage->relativePath($id, $source), $preset->processingOptions());
    }

    private static function status(int $status): Response
    {
        return new Response('', $status, ['Cache-Control' => 'private, no-store']);
    }
}
