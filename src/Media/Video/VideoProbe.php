<?php

declare(strict_types=1);

namespace App\Media\Video;

final readonly class VideoProbe
{
    public function __construct(
        public float $durationSeconds,
        public int $width,
        public int $height,
        public string $videoCodec,
        public bool $hasAudio,
    ) {}

    /**
     * @param array<array-key, mixed> $data Ausgabe von `ffprobe -print_format json -show_format -show_streams`
     *
     * @throws VideoRejected
     */
    public static function fromFfprobe(array $data): self
    {
        $video = null;
        $hasAudio = false;

        foreach (array_filter(self::arrayField($data, 'streams'), is_array(...)) as $stream) {
            $type = self::stringField($stream, 'codec_type');
            $hasAudio = $hasAudio || 'audio' === $type;
            if ('video' === $type) {
                $video ??= $stream;
            }
        }

        if (null === $video) {
            throw new VideoRejected('Datei enthält keine Videospur.');
        }

        $format = self::arrayField($data, 'format');

        return new self(
            array_key_exists('duration', $format) && is_numeric($format['duration'])
                ? (float) $format['duration']
                : 0.0,
            self::intField($video, 'width'),
            self::intField($video, 'height'),
            self::stringField($video, 'codec_name') ?? 'unknown',
            $hasAudio,
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function arrayField(array $data, string $key): array
    {
        return array_key_exists($key, $data) && is_array($data[$key]) ? $data[$key] : [];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function stringField(array $data, string $key): ?string
    {
        return array_key_exists($key, $data) && is_string($data[$key]) ? $data[$key] : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function intField(array $data, string $key): int
    {
        return array_key_exists($key, $data) && is_int($data[$key]) ? $data[$key] : 0;
    }
}
