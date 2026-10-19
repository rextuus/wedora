<?php

declare(strict_types=1);

namespace App\Guest\Portrait;

use App\Guest\Entity\Guest;
use App\Wedding\Entity\Wedding;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Ablage der Porträts: <MEDIA_ROOT>/portraits/<hochzeit>/<gast>.png (privat, Auslieferung nur über Symfony).
 * Das Selfie liegt nur zwischen Aufnahme und Zeichnung daneben und wird danach immer gelöscht.
 */
final readonly class PortraitStorage
{
    private Filesystem $filesystem;

    public function __construct(
        #[Autowire(env: 'MEDIA_ROOT')]
        private string $mediaRoot,
    ) {
        $this->filesystem = new Filesystem();
    }

    public function portraitPath(Guest $guest): string
    {
        return $this->weddingDir($guest->getWedding()) . '/' . $guest->getId()->toRfc4122() . '.png';
    }

    public function selfiePath(Guest $guest): string
    {
        return $this->weddingDir($guest->getWedding()) . '/' . $guest->getId()->toRfc4122() . '.selfie';
    }

    public function keepSelfie(Guest $guest, string $uploadedPath): void
    {
        $this->filesystem->mkdir($this->weddingDir($guest->getWedding()), 0o750);
        $this->filesystem->copy($uploadedPath, $this->selfiePath($guest), true);
    }

    public function savePortrait(Guest $guest, string $localPath): void
    {
        $this->filesystem->mkdir($this->weddingDir($guest->getWedding()), 0o750);
        $this->filesystem->rename($localPath, $this->portraitPath($guest), true);
    }

    public function deleteSelfie(Guest $guest): void
    {
        $this->filesystem->remove($this->selfiePath($guest));
    }

    public function deleteFor(Guest $guest): void
    {
        $this->filesystem->remove([$this->selfiePath($guest), $this->portraitPath($guest)]);
    }

    public function deleteAllFor(Wedding $wedding): void
    {
        $this->filesystem->remove($this->weddingDir($wedding));
    }

    private function weddingDir(Wedding $wedding): string
    {
        return $this->mediaRoot . '/portraits/' . $wedding->getId()->toRfc4122();
    }
}
