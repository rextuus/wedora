<?php

declare(strict_types=1);

namespace App\Media\Image;

use App\Media\MediaId;
use App\Media\Storage\MediaFile;
use App\Media\Storage\MediaStorage;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * „Fotos automatisch angleichen“: eine ruhigere Fassung jedes Fotos für die Anzeige, das Original bleibt.
 *
 * imgproxy liefert das Foto richtig gedreht (EXIF), verkleinert und als JPEG – auch aus HEIC. ffmpeg gleicht dann
 * den Farbstich mäßig aus und macht das Foto ruhiger (siehe FILTER).
 */
final readonly class PhotoEnhancer
{
    /** Größer als die größte Anzeige-Variante, damit nichts unscharf wird. */
    public const int MAX_EDGE = 2560;

    /**
     * Mäßiger Ausgleich des Farbstichs je Kanal, dann ruhiger und weicher: weniger Sättigung und Kontrast, etwas
     * heller, leicht nachgeschärft. Am Beispiel (20.10.) rückten knallige Handyfotos deutlich an die ruhigen
     * Beispielbilder heran, ohne gute Fotos kaputtzumachen.
     */
    public const string FILTER =
        'normalize=blackpt=black:whitept=white:smoothing=0:independence=1:strength=0.5,'
            . 'eq=saturation=0.82:gamma=1.08:contrast=0.93,'
            . 'unsharp=5:5:0.25:5:5:0';

    public function __construct(
        private HttpClientInterface $httpClient,
        private ImgproxyUrlSigner $imgproxy,
        private MediaStorage $storage,
        #[Autowire(env: 'IMGPROXY_INTERNAL_URL')]
        private string $imgproxyUrl,
        private string $ffmpeg = 'ffmpeg',
    ) {}

    /** Erstellt die angeglichene Fassung; liefert ihre Größe in Bytes. */
    public function enhance(MediaId $id): int
    {
        // Zwischendateien im Ordner des Mediums: gleiche Platte, also einfaches Umbenennen am Ende.
        $target = $this->storage->localPath($id, MediaFile::Enhanced);
        new Filesystem()->mkdir(dirname($target), 0o750);
        $oriented = $target . '.in.jpg';
        $enhanced = $target . '.part.jpg';

        try {
            $source = 'local:///' . $this->storage->relativePath($id, MediaFile::Original);
            $url =
                rtrim($this->imgproxyUrl, '/')
                . $this->imgproxy->sign(
                    $source,
                    [
                        sprintf('rs:fit:%1$d:%1$d:0', self::MAX_EDGE),
                        'q:92',
                    ],
                    'jpg',
                );
            $response = $this->httpClient->request('GET', $url, ['timeout' => 60]);
            if (200 !== $response->getStatusCode()) {
                throw new RuntimeException(sprintf('imgproxy antwortet mit %d.', $response->getStatusCode()));
            }

            new Filesystem()->dumpFile($oriented, $response->getContent());

            $process = new Process([
                $this->ffmpeg,
                '-nostdin',
                '-v',
                'error',
                '-y',
                '-i',
                $oriented,
                '-vf',
                self::FILTER,
                '-frames:v',
                '1',
                '-q:v',
                '3',
                $enhanced,
            ]);
            $process->setTimeout(60);
            $process->run();
            if (!$process->isSuccessful() || !is_file($enhanced)) {
                throw new RuntimeException('Foto konnte nicht angeglichen werden: ' . trim($process->getErrorOutput()));
            }

            $this->storage->adopt($id, MediaFile::Enhanced, $enhanced);

            return $this->storage->size($id, MediaFile::Enhanced);
        } finally {
            new Filesystem()->remove([$oriented, $enhanced]);
        }
    }

    /** Entfernt die angeglichene Fassung (Anzeige wieder aus dem Original). */
    public function remove(MediaId $id): void
    {
        if ($this->storage->exists($id, MediaFile::Enhanced)) {
            new Filesystem()->remove($this->storage->localPath($id, MediaFile::Enhanced));
        }
    }
}
