<?php

declare(strict_types=1);

namespace App\Media\Image;

use App\Media\MediaId;
use App\Media\Storage\MediaFile;
use App\Media\Storage\MediaStorage;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Anzeige-Varianten (Kachel, Großansicht, Mini-Vorschau) einmal im Worker berechnen und als Datei ablegen.
 * Sonst rechnet imgproxy bei jedem Abruf neu – am Hochzeitsabend mit vielen Gästen der Engpass.
 */
final readonly class ImageVariants
{
    public function __construct(
        private ImgproxyClient $imgproxy,
        private MediaStorage $storage,
    ) {}

    /** Woraus die Varianten entstehen: Standbild beim Video, sonst die angeglichene Fassung oder das Original. */
    public function source(MediaId $id): ?MediaFile
    {
        foreach ([MediaFile::Poster, MediaFile::Enhanced, MediaFile::Original] as $candidate) {
            if ($this->storage->exists($id, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Berechnet alle Varianten (neu, z. B. nachdem sich die Quelle geändert hat). */
    public function generate(MediaId $id): void
    {
        $source = $this->source($id);
        if (null === $source || MediaFile::Original === $source && $this->storage->exists($id, MediaFile::Video)) {
            return;
        }

        foreach (ImagePreset::cases() as $preset) {
            // Erst fertig schreiben, dann umbenennen: Caddy liefert nie eine halbe Datei aus.
            $part = $this->storage->localPath($id, $preset->file()) . '.part';
            $filesystem = new Filesystem();
            $filesystem->dumpFile($part, $this->imgproxy->fetch($id, $source, $preset->processingOptions(), 'webp'));
            $filesystem->chmod($part, 0o644);
            $this->storage->adopt($id, $preset->file(), $part);
        }
    }

    public function has(MediaId $id, ImagePreset $preset): bool
    {
        return $this->storage->exists($id, $preset->file());
    }

    /** Größe aller abgeleiteten Dateien (alles außer dem Original), für den Speicherverbrauch der Hochzeit. */
    public function derivedBytes(MediaId $id): int
    {
        $bytes = 0;
        foreach (MediaFile::cases() as $file) {
            if (MediaFile::Original !== $file) {
                $bytes += $this->storage->size($id, $file);
            }
        }

        return $bytes;
    }
}
