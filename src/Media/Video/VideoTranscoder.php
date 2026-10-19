<?php

declare(strict_types=1);

namespace App\Media\Video;

interface VideoTranscoder
{
    /**
     * @throws VideoRejected
     */
    public function probe(string $inputPath): VideoProbe;

    /**
     * Erzeugt Wiedergabe-MP4 und Standbild. Ausgaben entstehen atomar (erst temporär, dann umbenannt).
     *
     * @throws VideoRejected
     * @throws VideoTranscodingFailed
     */
    public function transcode(string $inputPath, string $videoOutputPath, string $posterOutputPath): VideoProbe;
}
