<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Gemini\GeminiImageGenerator;
use App\Ai\ImageGenerationFailed;
use App\Ai\ImageInput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GeminiImageGeneratorTest extends TestCase
{
    public function testSendsPromptAndImageAndReturnsGeneratedImage(): void
    {
        $response = self::json([
            'candidates' => [[
                'content' => [
                    'parts' => [
                        ['text' => 'Hier ist das Bild'],
                        ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('PNGDATA')]],
                    ],
                ],
            ]],
        ]);
        $generator = new GeminiImageGenerator(new MockHttpClient($response), 'geheim', 'gemini-test-image');

        $image = $generator->generate('Zeichne', [new ImageInput('JPEGDATA', 'image/jpeg')], '3:4');

        self::assertSame('PNGDATA', $image->bytes);
        self::assertSame('image/png', $image->mimeType);
        self::assertSame(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-test-image:generateContent',
            $response->getRequestUrl(),
        );
        $options = $response->getRequestOptions();
        // @mago-expect analysis:mixed-assignment
        $headers = $options['headers'] ?? null;
        self::assertIsArray($headers);
        self::assertContains('x-goog-api-key: geheim', $headers);

        // @mago-expect analysis:mixed-assignment
        $rawBody = $options['body'] ?? null;
        self::assertIsString($rawBody);
        /** @var array{contents: list<array{parts: list<array<string, mixed>>}>, generationConfig: array<string, mixed>} $body */
        $body = json_decode($rawBody, true);
        self::assertSame(
            ['mime_type' => 'image/jpeg', 'data' => base64_encode('JPEGDATA')],
            $body['contents'][0]['parts'][0]['inline_data'],
        );
        self::assertSame('Zeichne', $body['contents'][0]['parts'][1]['text']);
        self::assertSame(
            ['responseModalities' => ['IMAGE'], 'imageConfig' => ['aspectRatio' => '3:4']],
            $body['generationConfig'],
        );
    }

    public function testWithoutKeyTheFeatureIsUnavailableAndNothingIsSent(): void
    {
        $client = new MockHttpClient(static fn() => throw new \LogicException('Darf nicht aufgerufen werden.'));
        $generator = new GeminiImageGenerator($client, '', 'gemini-test-image');

        self::assertFalse($generator->isAvailable());
        $this->expectException(ImageGenerationFailed::class);
        $generator->generate('Zeichne');
    }

    public function testBlockedPromptIsReportedWithReason(): void
    {
        $generator = new GeminiImageGenerator(
            new MockHttpClient(self::json(['promptFeedback' => ['blockReason' => 'SAFETY']])),
            'geheim',
            'gemini-test-image',
        );

        try {
            $generator->generate('Zeichne');
            self::fail('Fehler erwartet.');
        } catch (ImageGenerationFailed $e) {
            self::assertStringContainsString('SAFETY', $e->getMessage());
        }
    }

    public function testHttpErrorIsReportedWithoutLeakingTheKey(): void
    {
        $generator = new GeminiImageGenerator(
            new MockHttpClient(self::json(['error' => ['message' => 'API key not valid']], 400)),
            'geheim',
            'gemini-test-image',
        );

        try {
            $generator->generate('Zeichne');
            self::fail('Fehler erwartet.');
        } catch (ImageGenerationFailed $e) {
            self::assertStringContainsString('HTTP 400', $e->getMessage());
            self::assertStringContainsString('API key not valid', $e->getMessage());
            self::assertStringNotContainsString('geheim', $e->getMessage());
        }
    }

    /**
     * @param array<mixed> $body
     */
    private static function json(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
