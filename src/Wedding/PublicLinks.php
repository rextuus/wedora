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
    ) {}

    public function join(Wedding $wedding): string
    {
        return $this->absolute($this->urls->generate('guest_join', [
            'slug' => $wedding->getSlug(),
            'k' => $wedding->access()->openLinkToken(),
        ]));
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
