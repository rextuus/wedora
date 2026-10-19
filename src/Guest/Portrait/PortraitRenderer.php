<?php

declare(strict_types=1);

namespace App\Guest\Portrait;

use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Macht aus der KI-Zeichnung (schwarze Linien auf Weiß) ein quadratisches PNG mit schwarzen Linien
 * auf transparentem Grund. Die Oberfläche färbt es per CSS-Maske in der Akzentfarbe ein.
 */
final readonly class PortraitRenderer
{
    public const int SIZE = 768;

    public function __construct(
        private string $ffmpeg = 'ffmpeg',
        private int $timeoutSeconds = 60,
    ) {}

    public function render(string $drawingBytes, string $outputPath): void
    {
        $input = $outputPath . '.in';
        if (false === file_put_contents($input, $drawingBytes)) {
            throw new RuntimeException('Zeichnung kann nicht zwischengespeichert werden.');
        }

        // Helligkeit wird zur Deckkraft: Weiß verschwindet, Linien bleiben; leichtes Papierrauschen wird abgeschnitten.
        $filter = sprintf(
            '[0:v]scale=%1$d:%1$d:force_original_aspect_ratio=decrease,pad=%1$d:%1$d:(ow-iw)/2:(oh-ih)/2:color=white,'
            . "format=gray,negate,lutyuv=y='if(lt(val,28),0,min(255,val*1.5))'[a];"
            . 'color=c=black:s=%1$dx%1$d,format=rgba[c];[c][a]alphamerge',
            self::SIZE,
        );

        $process = new Process([
            $this->ffmpeg,
            '-nostdin',
            '-v',
            'error',
            '-y',
            '-i',
            $input,
            '-filter_complex',
            $filter,
            '-frames:v',
            '1',
            '-f',
            'image2',
            '-c:v',
            'png',
            $outputPath,
        ]);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } finally {
            new Filesystem()->remove($input);
        }

        if (!$process->isSuccessful() || !is_file($outputPath)) {
            throw new RuntimeException(
                'Zeichnung konnte nicht umgewandelt werden: ' . trim($process->getErrorOutput()),
            );
        }
    }
}
