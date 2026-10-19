<?php

declare(strict_types=1);

namespace App\Demo;

use App\Guest\Entity\Guest;
use App\Media\Entity\Media;
use App\Media\MediaType;
use App\Media\Upload\UploadRejected;
use App\Media\Upload\UploadRequest;
use App\Media\Upload\UploadService;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Lädt eine lokale Datei stückweise über den regulären Upload-Weg hoch, als hätte der Gast sie geschickt.
 * Eine gleichnamige .txt-Datei wird zur Bildunterschrift.
 */
final readonly class DemoMediaImporter
{
    public function __construct(
        private UploadService $uploads,
    ) {}

    /**
     * @throws UploadRejected
     */
    public function import(Guest $guest, SplFileInfo $file): Media
    {
        $isVideo = 1 === preg_match('/\\.(mp4|mov)$/i', $file->getFilename());
        $upload = $this->uploads->start(
            $guest,
            new UploadRequest(
                $isVideo ? MediaType::Video : MediaType::Photo,
                (int) $file->getSize(),
                caption: self::caption($file),
            ),
        );

        $stream = fopen($file->getPathname(), 'rb');
        if (false === $stream) {
            throw new UploadRejected('Datei nicht lesbar.');
        }

        try {
            do {
                $media = $this->uploads->append($upload, $upload->getReceivedBytes(), $stream);
            } while (null === $media && !feof($stream));
        } finally {
            fclose($stream);
        }

        return $media ?? throw new UploadRejected('Datei unvollständig gelesen.');
    }

    private static function caption(SplFileInfo $file): ?string
    {
        $path = (string) preg_replace('/\\.[^.]+$/', '.txt', $file->getPathname());
        $caption = is_file($path) ? trim((string) file_get_contents($path)) : '';

        return '' === $caption ? null : $caption;
    }
}
