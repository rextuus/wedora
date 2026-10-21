<?php

declare(strict_types=1);

namespace App\Media\Image;

use App\Media\MediaId;
use App\Media\Storage\MediaFile;
use App\Media\Storage\MediaStorage;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Ruft das interne imgproxy aus App und Worker direkt auf (nicht über Caddy), z. B. um Varianten vorzuberechnen.
 */
final readonly class ImgproxyClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ImgproxyUrlSigner $signer,
        private MediaStorage $storage,
        #[Autowire(env: 'IMGPROXY_INTERNAL_URL')]
        private string $baseUrl,
    ) {}

    /**
     * @param list<string> $processingOptions
     */
    public function fetch(MediaId $id, MediaFile $source, array $processingOptions, string $extension): string
    {
        $url =
            rtrim($this->baseUrl, '/')
            . $this->signer->sign(
                'local:///' . $this->storage->relativePath($id, $source),
                $processingOptions,
                $extension,
            );
        $response = $this->httpClient->request('GET', $url, ['timeout' => 60]);
        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(sprintf('imgproxy antwortet mit %d.', $response->getStatusCode()));
        }

        return $response->getContent();
    }
}
