<?php

declare(strict_types=1);

namespace App\Media\Storage;

use App\Media\MediaId;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

final readonly class LocalMediaStorage implements MediaStorage
{
    private Filesystem $filesystem;

    public function __construct(
        private string $root,
    ) {
        if ('' === $root || !str_starts_with($root, '/')) {
            throw new InvalidArgumentException('MEDIA_ROOT muss ein absoluter Pfad sein.');
        }

        $this->filesystem = new Filesystem();
    }

    public function store(MediaId $id, MediaFile $file, mixed $source): void
    {
        $target = $this->localPath($id, $file);
        $this->filesystem->mkdir(dirname($target), 0o750);

        if (is_string($source)) {
            $this->filesystem->copy($source, $target, true);

            return;
        }

        if (!is_resource($source)) {
            throw new InvalidArgumentException('Quelle muss ein Stream oder Dateipfad sein.');
        }

        $handle = fopen($target, 'wb');
        if (false === $handle) {
            throw new RuntimeException('Zieldatei kann nicht geschrieben werden.');
        }

        try {
            if (false === stream_copy_to_stream($source, $handle)) {
                throw new RuntimeException('Kopieren in den Medienspeicher fehlgeschlagen.');
            }
        } finally {
            fclose($handle);
        }
    }

    public function adopt(MediaId $id, MediaFile $file, string $localPath): void
    {
        $target = $this->localPath($id, $file);
        $this->filesystem->mkdir(dirname($target), 0o750);
        $this->filesystem->rename($localPath, $target, true);
    }

    public function exists(MediaId $id, MediaFile $file): bool
    {
        return is_file($this->localPath($id, $file));
    }

    public function size(MediaId $id, MediaFile $file): int
    {
        $path = $this->localPath($id, $file);
        $size = is_file($path) ? filesize($path) : false;

        return false === $size ? 0 : $size;
    }

    public function localPath(MediaId $id, MediaFile $file): string
    {
        return $this->root . '/' . $this->relativePath($id, $file);
    }

    public function relativePath(MediaId $id, MediaFile $file): string
    {
        // Zweistufige Verteilung, damit kein Verzeichnis zehntausende Einträge bekommt.
        return sprintf('%s/%s/%s', substr($id->value, 0, 2), $id->value, $file->value);
    }

    public function deleteAll(MediaId $id): void
    {
        $this->filesystem->remove(dirname($this->localPath($id, MediaFile::Original)));
    }

    public function list(MediaId $id): array
    {
        $dir = dirname($this->localPath($id, MediaFile::Original));
        if (!is_dir($dir)) {
            return [];
        }

        $entries = scandir($dir);

        return array_values(array_filter(
            false === $entries ? [] : $entries,
            static fn(string $entry): bool => '.' !== $entry && '..' !== $entry,
        ));
    }
}
