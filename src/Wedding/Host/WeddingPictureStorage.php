<?php

declare(strict_types=1);

namespace App\Wedding\Host;

use App\Wedding\Entity\Wedding;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Bilder der Hochzeit (siehe WeddingPicture): <MEDIA_ROOT>/<art>/<hochzeit>.jpg, verkleinert.
 * Beim Verkleinern gehen auch alle Metadaten (z. B. GPS) verloren.
 */
final readonly class WeddingPictureStorage
{
    public function __construct(
        #[Autowire(env: 'MEDIA_ROOT')]
        private string $mediaRoot,
        private string $ffmpeg = 'ffmpeg',
    ) {}

    public function path(Wedding $wedding, WeddingPicture $picture): string
    {
        return $this->mediaRoot . '/' . $picture->value . '/' . $wedding->getId()->toRfc4122() . '.jpg';
    }

    public function save(Wedding $wedding, WeddingPicture $picture, string $uploadedPath): void
    {
        $target = $this->path($wedding, $picture);
        new Filesystem()->mkdir(dirname($target), 0o750);
        $tmp = $target . '.part.jpg';

        $process = new Process([
            $this->ffmpeg,
            '-nostdin',
            '-v',
            'error',
            '-y',
            '-i',
            $uploadedPath,
            '-vf',
            $picture->filter(),
            '-frames:v',
            '1',
            '-map_metadata',
            '-1',
            '-q:v',
            '4',
            $tmp,
        ]);
        $process->setTimeout(30);
        $process->run();
        if (!$process->isSuccessful() || !is_file($tmp)) {
            throw new RuntimeException('Foto konnte nicht verarbeitet werden.');
        }

        new Filesystem()->rename($tmp, $target, true);
    }

    public function delete(Wedding $wedding, WeddingPicture $picture): void
    {
        new Filesystem()->remove($this->path($wedding, $picture));
    }

    public function deleteAll(Wedding $wedding): void
    {
        foreach (WeddingPicture::cases() as $picture) {
            $this->delete($wedding, $picture);
        }
    }
}
