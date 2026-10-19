<?php

declare(strict_types=1);

namespace App\Wedding;

use App\Guest\Portrait\PortraitStorage;
use App\Media\Entity\Media;
use App\Media\Entity\Upload;
use App\Media\Export\ExportFiles;
use App\Media\Storage\MediaStorage;
use App\Media\Upload\UploadBuffer;
use App\Wedding\Entity\Wedding;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Vollständige, endgültige Löschung einer Hochzeit (FR-14): Originale, Varianten,
 * Standbilder, offene Upload-Stücke, Export-Archive, gezeichnete Porträts und alle Datensätze.
 * Es gibt keinen Bild-Cache. Selfies für Porträts gehen nur flüchtig an Gemini und liegen
 * bei uns höchstens bis zur fertigen Zeichnung (siehe ADR 0005).
 */
final readonly class WeddingEraser
{
    public function __construct(
        private EntityManagerInterface $em,
        private MediaStorage $storage,
        private UploadBuffer $uploads,
        private ExportFiles $exports,
        private PortraitStorage $portraits,
    ) {}

    public function erase(Wedding $wedding): void
    {
        foreach ($this->em->getRepository(Media::class)->findBy(['wedding' => $wedding]) as $media) {
            $this->storage->deleteAll($media->mediaId());
        }

        /** @var list<Upload> $openUploads */
        $openUploads = $this->em
            ->createQueryBuilder()
            ->select('u')
            ->from(Upload::class, 'u')
            ->join('u.guest', 'g')
            ->where('g.wedding = :wedding')
            ->setParameter('wedding', $wedding->getId(), UuidType::NAME)
            ->getQuery()
            ->getResult();
        foreach ($openUploads as $upload) {
            $this->uploads->delete($upload);
        }

        $this->exports->deleteAllFor($wedding);
        $this->portraits->deleteAllFor($wedding);

        // Datenbank: Fremdschlüssel mit ON DELETE CASCADE entfernen Gäste, Einladungen,
        // Mitgliedschaften, Module, Medien, Uploads und Exporte.
        $id = $wedding->getId()->toBinary();
        $this->em->clear();
        $this->em->getConnection()->delete('wedding', ['id' => $id]);
    }
}
