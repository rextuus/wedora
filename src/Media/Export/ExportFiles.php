<?php

declare(strict_types=1);

namespace App\Media\Export;

use App\Media\Entity\Export;
use App\Wedding\Entity\Wedding;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Ablage der Export-Archive im Medien-Volume (nie direkt erreichbar; Auslieferung per X-Accel-Redirect).
 */
final readonly class ExportFiles
{
    public const string DIR = '_exports';

    public function __construct(
        private string $mediaRoot,
    ) {}

    public function relativePath(Export $export): string
    {
        return sprintf(
            '%s/%s/%s.zip',
            self::DIR,
            $export->getWedding()->getId()->toRfc4122(),
            $export->getId()->toRfc4122(),
        );
    }

    public function localPath(Export $export): string
    {
        return rtrim($this->mediaRoot, '/') . '/' . $this->relativePath($export);
    }

    public function delete(Export $export): void
    {
        new Filesystem()->remove($this->localPath($export));
    }

    public function deleteAllFor(Wedding $wedding): void
    {
        new Filesystem()->remove(sprintf(
            '%s/%s/%s',
            rtrim($this->mediaRoot, '/'),
            self::DIR,
            $wedding->getId()->toRfc4122(),
        ));
    }
}
