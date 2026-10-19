<?php

declare(strict_types=1);

namespace App\Ai;

final readonly class ImageInput
{
    public function __construct(
        public string $bytes,
        public string $mimeType,
    ) {}

    public static function fromFile(string $path, string $mimeType): self
    {
        $bytes = file_get_contents($path);
        if (false === $bytes) {
            throw new ImageGenerationFailed('Vorlagebild nicht lesbar.');
        }

        return new self($bytes, $mimeType);
    }
}
