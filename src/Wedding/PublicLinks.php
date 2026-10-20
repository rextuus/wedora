<?php

declare(strict_types=1);

namespace App\Wedding;

use App\Wedding\Entity\Wedding;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Links, die gedruckt oder verschickt werden (QR-Code, Einladungen), immer mit der
 * konfigurierten öffentlichen Adresse – unabhängig davon, wie die Verwaltung gerade aufgerufen wird.
 */
final readonly class PublicLinks
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        #[Autowire('%env(DEFAULT_URI)%')]
        private string $publicBaseUrl,
        #[Autowire('%env(LAN_BASE_URL)%')]
        private string $lanBaseUrl = '',
    ) {}

    /**
     * Derselbe Beitrittslink über die Adresse im Heimnetz – nur in der lokalen Entwicklung (LAN_BASE_URL),
     * damit man mit dem Handy testen kann. Sonst null.
     */
    public function lanJoin(Wedding $wedding): ?string
    {
        if ('' === $this->lanBaseUrl || rtrim($this->lanBaseUrl, '/') === rtrim($this->publicBaseUrl, '/')) {
            return null;
        }

        return rtrim($this->lanBaseUrl, '/') . $this->joinPath($wedding);
    }

    public function join(Wedding $wedding): string
    {
        return $this->absolute($this->joinPath($wedding));
    }

    private function joinPath(Wedding $wedding): string
    {
        return $this->urls->generate('guest_join', [
            'slug' => $wedding->getSlug(),
            'k' => $wedding->access()->openLinkToken(),
        ]);
    }

    public function invitation(#[SensitiveParameter] string $token): string
    {
        return $this->absolute($this->urls->generate('guest_invitation', ['token' => $token]));
    }

    private function absolute(string $path): string
    {
        return rtrim($this->publicBaseUrl, '/') . $path;
    }
}
