<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Erzeugt Testvideos zur Laufzeit mit ffmpeg (keine großen Binärdateien im Repository).
 */
final class VideoFixtures
{
    private static ?string $dir = null;

    /** Querformat 1920×1080, H.264/AAC, mit Ortsangabe in den Metadaten. */
    public static function landscapeH264(): string
    {
        return self::make('landscape.mp4', [
            '-f',
            'lavfi',
            '-i',
            'testsrc2=duration=4:size=1920x1080:rate=25',
            '-f',
            'lavfi',
            '-i',
            'sine=frequency=440:duration=4',
            '-c:v',
            'libx264',
            '-preset',
            'ultrafast',
            '-pix_fmt',
            'yuv420p',
            '-c:a',
            'aac',
            '-shortest',
            '-metadata',
            'location=+52.5200+013.4050/',
            '-metadata',
            'title=Privat',
        ]);
    }

    /** Hochformat 1080×1920, HEVC in MOV – typisch für iPhones. */
    public static function portraitHevcMov(): string
    {
        return self::make('portrait.mov', [
            '-f',
            'lavfi',
            '-i',
            'testsrc2=duration=3:size=1080x1920:rate=30',
            '-f',
            'lavfi',
            '-i',
            'sine=frequency=880:duration=3',
            '-c:v',
            'libx265',
            '-preset',
            'ultrafast',
            '-tag:v',
            'hvc1',
            '-pix_fmt',
            'yuv420p',
            '-c:a',
            'aac',
            '-shortest',
            '-x265-params',
            'log-level=error',
        ]);
    }

    /** Länger als das Limit (Tests setzen 30 s). */
    public static function tooLong(): string
    {
        return self::make('long.mp4', [
            '-f',
            'lavfi',
            '-i',
            'color=c=black:size=160x90:rate=5:duration=40',
            '-c:v',
            'libx264',
            '-preset',
            'ultrafast',
        ]);
    }

    public static function audioOnly(): string
    {
        return self::make('audio.m4a', ['-f', 'lavfi', '-i', 'sine=frequency=440:duration=2', '-c:a', 'aac']);
    }

    public static function garbage(): string
    {
        $path = self::dir() . '/garbage.mp4';
        file_put_contents($path, random_bytes(64 * 1024));

        return $path;
    }

    /**
     * @param list<string> $args
     */
    private static function make(string $name, array $args): string
    {
        $path = self::dir() . '/' . $name;
        if (is_file($path)) {
            return $path;
        }

        $process = new Process(['ffmpeg', '-nostdin', '-y', '-hide_banner', '-loglevel', 'error', ...$args, $path]);
        $process->setTimeout(120);
        $process->mustRun();

        return $path;
    }

    private static function dir(): string
    {
        if (null === self::$dir) {
            self::$dir = sys_get_temp_dir() . '/wedora-video-fixtures';
            if (!is_dir(self::$dir) && !mkdir(self::$dir, 0o700, true)) {
                throw new RuntimeException('Fixture-Verzeichnis nicht anlegbar.');
            }
        }

        return self::$dir;
    }
}
