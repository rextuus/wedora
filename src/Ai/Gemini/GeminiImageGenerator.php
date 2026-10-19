<?php

declare(strict_types=1);

namespace App\Ai\Gemini;

use App\Ai\GeneratedImage;
use App\Ai\ImageGenerationFailed;
use App\Ai\ImageGenerator;
use App\Ai\ImageInput;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bildgenerierung über die Gemini API (generateContent mit Bildausgabe).
 * Der Schlüssel kommt aus GEMINI_API_KEY (.env.local bzw. Server-Umgebung, nie ins Repository).
 */
#[AsAlias(ImageGenerator::class)]
final readonly class GeminiImageGenerator implements ImageGenerator
{
    private const string ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'GEMINI_API_KEY')]
        #[SensitiveParameter]
        private string $apiKey,
        #[Autowire(env: 'GEMINI_IMAGE_MODEL')]
        private string $model,
        private float $timeoutSeconds = 120.0,
    ) {}

    public function isAvailable(): bool
    {
        return '' !== $this->apiKey;
    }

    public function generate(string $prompt, array $images = [], string $aspectRatio = '1:1'): GeneratedImage
    {
        if (!$this->isAvailable()) {
            throw new ImageGenerationFailed('Kein Gemini-API-Schlüssel konfiguriert.');
        }

        $parts = array_map(static fn(ImageInput $image): array => [
            'inline_data' => ['mime_type' => $image->mimeType, 'data' => base64_encode($image->bytes)],
        ], $images);
        $parts[] = ['text' => $prompt];

        try {
            $response = $this->httpClient->request('POST', sprintf(self::ENDPOINT, rawurlencode($this->model)), [
                'headers' => ['x-goog-api-key' => $this->apiKey],
                'json' => [
                    'contents' => [['parts' => $parts]],
                    'generationConfig' => [
                        'responseModalities' => ['IMAGE'],
                        'imageConfig' => ['aspectRatio' => $aspectRatio],
                    ],
                ],
                'timeout' => $this->timeoutSeconds,
                'max_duration' => $this->timeoutSeconds,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new ImageGenerationFailed('Gemini nicht erreichbar: ' . $e->getMessage(), previous: $e);
        }

        if (200 !== $status) {
            $message = self::stringAt($data, 'error', 'message');
            throw new ImageGenerationFailed(sprintf(
                'Gemini antwortet mit HTTP %d%s',
                $status,
                null !== $message ? ': ' . $message : '',
            ));
        }

        return self::extractImage($data);
    }

    /**
     * @param array<mixed> $data
     */
    private static function extractImage(array $data): GeneratedImage
    {
        // @mago-expect analysis:mixed-assignment
        $parts = self::at($data, 'candidates', 0, 'content', 'parts');
        $count = is_array($parts) ? count($parts) : 0;
        for ($i = 0; $i < $count; ++$i) {
            $mime = self::stringAt($parts, $i, 'inlineData', 'mimeType');
            $encoded = self::stringAt($parts, $i, 'inlineData', 'data');
            if (null === $mime || null === $encoded || !str_starts_with($mime, 'image/')) {
                continue;
            }

            $bytes = base64_decode($encoded, true);
            if (false !== $bytes && '' !== $bytes) {
                return new GeneratedImage($bytes, $mime);
            }
        }

        $reason = self::stringAt($data, 'promptFeedback', 'blockReason') ?? self::stringAt(
            $data,
            'candidates',
            0,
            'finishReason',
        );

        throw new ImageGenerationFailed(sprintf(
            'Gemini hat kein Bild geliefert%s.',
            null !== $reason ? ' (' . $reason . ')' : '',
        ));
    }

    private static function stringAt(mixed $data, string|int ...$keys): ?string
    {
        // @mago-expect analysis:mixed-assignment
        $value = self::at($data, ...$keys);

        return is_string($value) ? $value : null;
    }

    /** Wert in verschachtelter JSON-Antwort oder null, wenn der Pfad fehlt. */
    private static function at(mixed $data, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }

            // @mago-expect analysis:mixed-assignment
            $data = $data[$key];
        }

        return $data;
    }
}
