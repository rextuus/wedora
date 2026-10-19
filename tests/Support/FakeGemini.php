<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Ersetzt in Tests alle ausgehenden HTTP-Anfragen (framework.http_client.mock_response_factory)
 * und antwortet wie die Gemini API. Kein Test spricht je mit Google.
 */
final class FakeGemini
{
    public const string ANSWER = 'image';
    public const string BLOCKED = 'blocked';
    public const string SERVER_ERROR = 'error';

    public static string $answer = self::ANSWER;

    /** @var list<array{method: string, url: string, options: array<mixed>}> */
    public static array $requests = [];

    public static function reset(): void
    {
        self::$answer = self::ANSWER;
        self::$requests = [];
    }

    /**
     * @param array<mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        self::$requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

        return match (self::$answer) {
            self::BLOCKED => self::json(['promptFeedback' => ['blockReason' => 'SAFETY']]),
            self::SERVER_ERROR => self::json(['error' => ['code' => 503, 'message' => 'overloaded']], 503),
            default => self::json([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'inlineData' => [
                                'mimeType' => 'image/png',
                                'data' => base64_encode((string) file_get_contents(__DIR__
                                . '/../Fixtures/line-drawing.png')),
                            ],
                        ]],
                    ],
                ]],
            ]),
        };
    }

    /**
     * @param array<mixed> $body
     */
    private static function json(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }
}
