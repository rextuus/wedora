<?php

declare(strict_types=1);

namespace App\Media\Upload;

use App\Media\Entity\Upload;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Zwischenspeicher für Upload-Stücke im Medien-Volume (gleiches Dateisystem,
 * damit der Abschluss ein schnelles Umbenennen ist).
 */
final readonly class UploadBuffer
{
    private string $dir;

    public function __construct(string $mediaRoot)
    {
        $this->dir = rtrim($mediaRoot, '/') . '/_uploads';
    }

    public function path(Upload $upload): string
    {
        return $this->dir . '/' . $upload->getId()->toRfc4122() . '.part';
    }

    /**
     * Hängt höchstens $maxBytes aus dem Stream an. Liefert die Zahl geschriebener Bytes
     * oder wirft, wenn der Stream mehr Daten enthält als erlaubt.
     *
     * @param resource $stream
     */
    public function append(Upload $upload, $stream, int $maxBytes): int
    {
        new Filesystem()->mkdir($this->dir, 0o750);
        $target = fopen($this->path($upload), 'ab');
        if (false === $target) {
            throw new RuntimeException('Upload-Puffer nicht beschreibbar.');
        }

        $written = 0;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, max(1, min(1024 * 1024, $maxBytes - $written + 1)));
                if (false === $chunk || '' === $chunk) {
                    break;
                }

                if (($written + strlen($chunk)) > $maxBytes) {
                    ftruncate($target, max(0, $upload->getReceivedBytes()));

                    throw new UploadRejected('Dieses Stück ist größer als erlaubt.', 413);
                }

                fwrite($target, $chunk);
                $written += strlen($chunk);
            }
        } finally {
            fclose($target);
        }

        return $written;
    }

    public function delete(Upload $upload): void
    {
        new Filesystem()->remove($this->path($upload));
    }
}
