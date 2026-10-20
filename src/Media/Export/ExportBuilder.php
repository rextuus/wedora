<?php

declare(strict_types=1);

namespace App\Media\Export;

use App\Media\Entity\Export;
use App\Media\Entity\Media;
use App\Media\MediaType;
use App\Media\PublicationStatus;
use App\Media\Repository\MediaRepository;
use App\Media\Storage\MediaFile;
use App\Media\Storage\MediaStorage;
use App\Media\Visibility;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use RuntimeException;
use SortDirection;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\AsciiSlugger;
use ZipArchive;

/**
 * Baut das ZIP für das Brautpaar: Originale aller Beiträge, die für Gäste oder das
 * Brautpaar bestimmt sind (ohne „nur für mich“, ohne abgelehnte, ohne gelöschte),
 * plus Übersicht als CSV. Medien sind bereits komprimiert → gespeichert ohne Kompression.
 */
final readonly class ExportBuilder
{
    private const array EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/webm' => 'webm',
        'video/3gpp' => '3gp',
    ];

    public function __construct(
        private MediaRepository $media,
        private MediaStorage $storage,
        private ExportFiles $files,
        private ClockInterface $clock,
    ) {}

    public function build(Export $export): void
    {
        $items = $this->exportableMedia($export);
        $target = $this->files->localPath($export);
        new Filesystem()->mkdir(dirname($target), 0o750);
        $tmp = $target . '.part';

        $zip = new ZipArchive();
        if (true !== $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            throw new RuntimeException('Export-Archiv kann nicht angelegt werden.');
        }

        $rows = [['Datei', 'Typ', 'Von', 'Zeitpunkt', 'Sichtbarkeit', 'Status']];
        $slugger = new AsciiSlugger('de');
        $count = 0;

        foreach ($items as $media) {
            $original = $this->storage->localPath($media->mediaId(), MediaFile::Original);
            if (!is_file($original)) {
                continue;
            }

            $name = self::entryName($media, $slugger);
            $zip->addFile($original, $name);
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
            $rows[] = self::overviewRow($media, $name);
            ++$count;
        }

        $zip->addFromString('uebersicht.csv', self::csv($rows));
        if (!$zip->close()) {
            throw new RuntimeException('Export-Archiv konnte nicht geschrieben werden.');
        }

        new Filesystem()->rename($tmp, $target, true);
        $export->markReady(
            (int) filesize($target),
            $count,
            DateTimeImmutable::createFromInterface($this->clock->now()),
        );
    }

    private static function uploaderName(Media $media): string
    {
        return (
            $media->getUploaderGuest()?->getDisplayName() ?? $media->getUploaderUser()?->getDisplayName() ?? 'unbekannt'
        );
    }

    private static function entryName(Media $media, AsciiSlugger $slugger): string
    {
        return sprintf(
            '%s/%s_%s_%s.%s',
            MediaType::Video === $media->getType() ? 'videos' : 'fotos',
            $media->getCreatedAt()->format('Y-m-d_His'),
            $slugger->slug(self::uploaderName($media))->lower()->truncate(30),
            substr($media->getId()->toRfc4122(), -8),
            self::EXTENSIONS[$media->getOriginalMimeType()] ?? 'bin',
        );
    }

    /**
     * @return list<string>
     */
    private static function overviewRow(Media $media, string $name): array
    {
        return [
            $name,
            MediaType::Video === $media->getType() ? 'Video' : 'Foto',
            self::uploaderName($media),
            $media->getCreatedAt()->format('d.m.Y H:i'),
            Visibility::Couple === $media->getVisibility() ? 'Brautpaar' : 'alle Gäste',
            PublicationStatus::Published === $media->getPublicationStatus() ? 'veröffentlicht' : 'wartet auf Freigabe',
        ];
    }

    /**
     * @return list<Media>
     */
    private function exportableMedia(Export $export): array
    {
        /** @var list<Media> */
        return $this->media
            ->createQueryBuilder('m')
            ->where('m.wedding = :wedding')
            ->andWhere('m.deletedAt IS NULL')
            ->andWhere('m.visibility != :private')
            ->andWhere('m.publicationStatus != :rejected')
            ->orderBy('m.createdAt', SortDirection::Ascending)
            ->setParameter('wedding', $export->getWedding()->getId(), UuidType::NAME)
            ->setParameter('private', Visibility::UploaderOnly)
            ->setParameter('rejected', PublicationStatus::Rejected)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<list<string>> $rows
     */
    private static function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if (false === $handle) {
            throw new RuntimeException('CSV kann nicht erzeugt werden.');
        }

        foreach ($rows as $row) {
            fputcsv($handle, $row, ';', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        // BOM, damit Excel Umlaute korrekt anzeigt.
        return "\u{FEFF}" . $csv;
    }
}
