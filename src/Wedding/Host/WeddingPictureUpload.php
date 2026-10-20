<?php

declare(strict_types=1);

namespace App\Wedding\Host;

use App\Wedding\Entity\Wedding;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Bild der Hochzeit aus der Verwaltung übernehmen: Format und Größe prüfen, verkleinert ablegen, Zeitstempel setzen.
 */
final readonly class WeddingPictureUpload
{
    public const int MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private WeddingPictureStorage $storage,
        private ClockInterface $clock,
    ) {}

    /**
     * @throws InvalidArgumentException|RuntimeException
     */
    public function replace(Wedding $wedding, WeddingPicture $picture, UploadedFile $file): void
    {
        $mime = $file->isValid() ? mime_content_type($file->getPathname()) : false;
        if (
            !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || (int) $file->getSize() > self::MAX_BYTES
        ) {
            throw new InvalidArgumentException(sprintf(
                '%s: bitte als JPEG, PNG oder WebP (höchstens 8 MB).',
                $picture->label(),
            ));
        }

        $this->storage->save($wedding, $picture, $file->getPathname());
        $wedding->getPresentation()->pictureChanged(
            $picture,
            DateTimeImmutable::createFromInterface($this->clock->now()),
        );
    }

    public function remove(Wedding $wedding, WeddingPicture $picture): void
    {
        $this->storage->delete($wedding, $picture);
        $wedding->getPresentation()->pictureChanged($picture, null);
    }
}
