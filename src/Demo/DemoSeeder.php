<?php

declare(strict_types=1);

namespace App\Demo;

use App\Guest\Entity\Guest;
use App\Guest\Repository\GuestRepository;
use App\Media\Entity\Media;
use App\Media\Upload\UploadRejected;
use App\Social\LikeService;
use App\Wedding\Entity\Wedding;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Finder\Finder;

/**
 * Beispielgäste und Beispielmedien für Vorführungen: erfundene Gäste, Uploads über den regulären Weg, zufällige Likes.
 */
final readonly class DemoSeeder
{
    public const array GUESTS = ['Marie', 'Jonas', 'Oma Ilse', 'Lea', 'Ben', 'Tante Petra', 'Can', 'Hannah'];

    public function __construct(
        private GuestRepository $guests,
        private DemoMediaImporter $importer,
        private LikeService $likes,
        private EntityManagerInterface $em,
    ) {}

    /**
     * Legt fehlende Beispielgäste an; vorhandene werden wiederverwendet.
     *
     * @return list<Guest>
     */
    public function guests(Wedding $wedding): array
    {
        $guests = [];
        foreach (self::GUESTS as $name) {
            $guest = $this->guests->findOneBy(['wedding' => $wedding, 'displayName' => $name]);
            if (null === $guest) {
                // Das Geheimnis wird verworfen: Beispielgäste melden sich nie an.
                [$guest] = Guest::join($wedding, $name, null);
                $this->em->persist($guest);
                $this->em->flush();
            }

            $guests[] = $guest;
        }

        return $guests;
    }

    /**
     * Spielt alle Medien eines Ordners (ohne Unterordner) reihum als Beispielgäste ein.
     *
     * @param callable(string): void $report Fortschritt bzw. Warnungen je Datei
     *
     * @return list<Media>
     */
    public function importDirectory(Wedding $wedding, string $directory, bool $withLikes, callable $report): array
    {
        $guests = $this->guests($wedding);
        $files = new Finder()->files()->in($directory)->depth(0)->name('/\.(jpe?g|png|webp|mp4|mov)$/i')->sortByName();
        $imported = [];
        foreach (iterator_to_array($files, false) as $i => $file) {
            $guest = $guests[$i % count($guests)];
            try {
                $imported[] = $this->importer->import($guest, $file);
                $report(sprintf(' ✓ %s von %s', $file->getFilename(), $guest->getDisplayName()));
            } catch (UploadRejected $e) {
                $report(sprintf(' ✗ %s: %s', $file->getFilename(), $e->getMessage()));
            }
        }

        if ($withLikes) {
            $this->likeRandomly($imported, $guests);
        }

        return $imported;
    }

    /**
     * @param list<Media> $media
     * @param list<Guest> $guests
     */
    private function likeRandomly(array $media, array $guests): void
    {
        foreach ($media as $item) {
            foreach ($guests as $guest) {
                if ($item->getUploaderGuest() !== $guest && random_int(1, 100) <= 35) {
                    $this->likes->toggle($guest, $item);
                }
            }
        }
    }
}
