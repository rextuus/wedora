<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media;

use App\Media\Video\FfmpegVideoTranscoder;
use App\Media\Video\VideoRejected;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Prüft die echte ffmpeg-Verarbeitung im Container.
 */
#[Group('media')]
final class FfmpegVideoTranscoderTest extends TestCase
{
    private string $out;

    protected function setUp(): void
    {
        $this->out = sys_get_temp_dir() . '/wedora-transcode-' . bin2hex(random_bytes(4));
        mkdir($this->out, 0o700);
    }

    protected function tearDown(): void
    {
        $files = glob($this->out . '/*');
        array_map(unlink(...), false === $files ? [] : $files);
        rmdir($this->out);
    }

    public function testLandscapeVideoBecomesBrowserCompatible720pMp4(): void
    {
        $probe = self::transcoder()
            ->transcode(VideoFixtures::landscapeH264(), "{$this->out}/video.mp4", "{$this->out}/poster.jpg");

        self::assertSame([1920, 1080], [$probe->width, $probe->height]);
        $result = self::ffprobe("{$this->out}/video.mp4");
        self::assertSame(['h264', 1280, 720, 'yuv420p'], [
            $result['vcodec'],
            $result['width'],
            $result['height'],
            $result['pix_fmt'],
        ]);
        self::assertSame('aac', $result['acodec']);
        self::assertFaststart("{$this->out}/video.mp4");
        self::assertFileExists("{$this->out}/poster.jpg");
        self::assertSame([1280, 720], array_slice((array) getimagesize("{$this->out}/poster.jpg"), 0, 2));
        self::assertSame([], glob("{$this->out}/*.part.*"), 'Keine temporären Dateien zurücklassen.');
    }

    public function testLocationAndOtherMetadataAreStripped(): void
    {
        self::transcoder()
            ->transcode(VideoFixtures::landscapeH264(), "{$this->out}/video.mp4", "{$this->out}/poster.jpg");

        $tags = self::ffprobe("{$this->out}/video.mp4")['tags'];
        self::assertArrayNotHasKey('location', $tags);
        self::assertArrayNotHasKey('title', $tags);
    }

    public function testPortraitHevcFromIphoneIsConvertedToH264(): void
    {
        $probe = self::transcoder()
            ->transcode(VideoFixtures::portraitHevcMov(), "{$this->out}/video.mp4", "{$this->out}/poster.jpg");

        self::assertSame('hevc', $probe->videoCodec);
        $result = self::ffprobe("{$this->out}/video.mp4");
        self::assertSame(['h264', 720, 1280], [$result['vcodec'], $result['width'], $result['height']]);
    }

    public function testVideoLongerThanLimitIsRejected(): void
    {
        $out = $this->out;
        $error = self::rejection(static fn() => self::transcoder()
            ->transcode(VideoFixtures::tooLong(), "{$out}/video.mp4", "{$out}/poster.jpg"));

        self::assertStringContainsString('länger als 30 Sekunden', $error->getMessage());
    }

    public function testGarbageIsRejectedWithoutOutput(): void
    {
        try {
            self::transcoder()
                ->transcode(VideoFixtures::garbage(), "{$this->out}/video.mp4", "{$this->out}/poster.jpg");
            self::fail('VideoRejected erwartet.');
        } catch (VideoRejected) {
            self::assertSame([], glob("{$this->out}/*"));
        }
    }

    public function testAudioOnlyFileIsRejected(): void
    {
        $error = self::rejection(static fn() => self::transcoder()->probe(VideoFixtures::audioOnly()));

        self::assertStringContainsString('keine Videospur', $error->getMessage());
    }

    private static function rejection(callable $action): VideoRejected
    {
        try {
            $action();
        } catch (VideoRejected $error) {
            return $error;
        }

        self::fail('VideoRejected erwartet.');
    }

    private static function transcoder(): FfmpegVideoTranscoder
    {
        return new FfmpegVideoTranscoder(maxDurationSeconds: 30, threads: 2, timeoutSeconds: 120);
    }

    /**
     * moov-Atom vor mdat: Wiedergabe startet, bevor die Datei vollständig geladen ist.
     */
    private static function assertFaststart(string $path): void
    {
        $head = (string) file_get_contents($path, false, null, 0, 64 * 1024);
        $moov = strpos($head, 'moov');
        self::assertNotFalse($moov, 'moov-Atom muss am Dateianfang liegen.');
        $mdat = strpos($head, 'mdat');
        self::assertTrue(false === $mdat || $moov < $mdat);
    }

    /**
     * @return array{vcodec: string, width: int, height: int, pix_fmt: string, acodec: string, tags: array<array-key, mixed>}
     */
    private static function ffprobe(string $path): array
    {
        $process = new Process([
            'ffprobe',
            '-v',
            'error',
            '-print_format',
            'json',
            '-show_format',
            '-show_streams',
            $path,
        ]);
        $process->mustRun();
        // @mago-expect analysis:mixed-assignment
        $data = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['streams']);
        self::assertIsArray($data['format']);
        $tags = array_key_exists('tags', $data['format']) && is_array($data['format']['tags'])
            ? $data['format']['tags']
            : [];
        $result = [
            'vcodec' => '',
            'width' => 0,
            'height' => 0,
            'pix_fmt' => '',
            'acodec' => '',
            'tags' => $tags,
        ];

        foreach (array_filter($data['streams'], is_array(...)) as $stream) {
            if ('video' === $stream['codec_type']) {
                self::assertIsString($stream['codec_name']);
                self::assertIsInt($stream['width']);
                self::assertIsInt($stream['height']);
                self::assertIsString($stream['pix_fmt']);
                [$result['vcodec'], $result['width'], $result['height'], $result['pix_fmt']] = [
                    $stream['codec_name'],
                    $stream['width'],
                    $stream['height'],
                    $stream['pix_fmt'],
                ];
            }

            if ('audio' === $stream['codec_type']) {
                self::assertIsString($stream['codec_name']);
                $result['acodec'] = $stream['codec_name'];
            }
        }

        return $result;
    }
}
