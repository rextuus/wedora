<?php

declare(strict_types=1);

namespace App\Controller;

use App\Media\Access\MediaAccessChecker;
use App\Media\Access\MediaAccessTokenSigner;
use App\Media\Image\ImagePreset;
use App\Media\Image\ImgproxyUrlSigner;
use App\Media\MediaId;
use App\Media\Storage\MediaFile;
use App\Media\Storage\MediaStorage;
use InvalidArgumentException;
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
        private LoggerInterface $logger,
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

        return new Response('', Response::HTTP_OK, [
            'X-Accel-Redirect' => $redirect,
            'Cache-Control' => 'private, no-store',
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
        // Bei Videos dient das Standbild als Bildquelle; bei Fotos die angeglichene Fassung, falls vorhanden.
        $source = MediaFile::Original;
        foreach ([MediaFile::Poster, MediaFile::Enhanced] as $candidate) {
            if ($this->storage->exists($id, $candidate)) {
                $source = $candidate;
                break;
            }
        }

        if (!$this->storage->exists($id, $source)) {
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
