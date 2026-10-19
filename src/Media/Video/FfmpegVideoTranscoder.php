<?php

declare(strict_types=1);

namespace App\Media\Video;

use JsonException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Transkodiert Gästevideos mit ffmpeg zu H.264/AAC-MP4 (lange Seite max. 1280 px, faststart).
 *
 * Metadaten (u. a. GPS-Position) werden entfernt. Ressourcen werden über Threads und
 * Timeout begrenzt; die Parallelität regelt die Anzahl der Worker.
 */
final readonly class FfmpegVideoTranscoder implements VideoTranscoder
{
    private const int LONG_EDGE = 1280;

    public function __construct(
        private int $maxDurationSeconds = 120,
        private int $threads = 2,
        private int $timeoutSeconds = 600,
        private string $ffmpeg = 'ffmpeg',
        private string $ffprobe = 'ffprobe',
    ) {}

    public function probe(string $inputPath): VideoProbe
    {
        $process = new Process([
            $this->ffprobe,
            '-v',
            'error',
            '-print_format',
            'json',
            '-show_format',
            '-show_streams',
            $inputPath,
        ]);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new VideoRejected('Datei ist kein lesbares Video.');
        }

        try {
            // @mago-expect analysis:mixed-assignment
            $data = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new VideoRejected('Videometadaten nicht lesbar.');
        }

        if (!is_array($data)) {
            throw new VideoRejected('Videometadaten nicht lesbar.');
        }

        $probe = VideoProbe::fromFfprobe($data);

        if ($probe->durationSeconds <= 0.0) {
            throw new VideoRejected('Videolänge nicht ermittelbar.');
        }

        if ($probe->durationSeconds > $this->maxDurationSeconds) {
            throw new VideoRejected(sprintf('Video ist länger als %d Sekunden.', $this->maxDurationSeconds));
        }

        return $probe;
    }

    public function transcode(string $inputPath, string $videoOutputPath, string $posterOutputPath): VideoProbe
    {
        $probe = $this->probe($inputPath);
        $scale = sprintf("scale='if(gte(iw,ih),min(%1\$d,iw),-2)':'if(gte(iw,ih),-2,min(%1\$d,ih))'", self::LONG_EDGE);

        $tmpVideo = $videoOutputPath . '.part.mp4';
        $this->run([
            $this->ffmpeg,
            '-nostdin',
            '-y',
            '-hide_banner',
            '-loglevel',
            'error',
            '-threads',
            (string) $this->threads,
            '-i',
            $inputPath,
            '-map',
            '0:v:0',
            '-map',
            '0:a:0?',
            '-vf',
            $scale . ',format=yuv420p',
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-crf',
            '23',
            '-profile:v',
            'high',
            '-c:a',
            'aac',
            '-b:a',
            '128k',
            '-ac',
            '2',
            '-map_metadata',
            '-1',
            '-movflags',
            '+faststart',
            $tmpVideo,
        ], $tmpVideo);

        $tmpPoster = $posterOutputPath . '.part.jpg';
        $this->run([
            $this->ffmpeg,
            '-nostdin',
            '-y',
            '-hide_banner',
            '-loglevel',
            'error',
            '-ss',
            sprintf('%.2F', min(1.0, $probe->durationSeconds / 2)),
            '-i',
            $tmpVideo,
            '-frames:v',
            '1',
            '-q:v',
            '3',
            '-map_metadata',
            '-1',
            $tmpPoster,
        ], $tmpPoster);

        if (!rename($tmpVideo, $videoOutputPath) || !rename($tmpPoster, $posterOutputPath)) {
            throw new VideoTranscodingFailed('Ausgabedateien konnten nicht abgelegt werden.');
        }

        return $probe;
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command, string $output): void
    {
        $process = new Process($command);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            self::remove($output);

            throw new VideoTranscodingFailed('Zeitlimit der Videoverarbeitung überschritten.');
        }

        if (!$process->isSuccessful() || !is_file($output) || 0 === filesize($output)) {
            self::remove($output);

            throw new VideoTranscodingFailed(
                'ffmpeg fehlgeschlagen: ' . mb_substr(trim($process->getErrorOutput()), 0, 500),
            );
        }
    }

    private static function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
