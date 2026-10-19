<?php

declare(strict_types=1);

namespace App\Media\Access;

use App\Media\Entity\Media;
use App\Security\Viewer;
use LogicException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Erzeugt kurzlebige, an Betrachter und Medium gebundene URLs. Die URL allein
 * berechtigt zu nichts: beim Abruf wird erneut geprüft.
 */
final readonly class MediaUrlGenerator
{
    public function __construct(
        private MediaAccessTokenSigner $tokens,
        private UrlGeneratorInterface $router,
    ) {}

    public function generate(Viewer $viewer, Media $media, string $variant): string
    {
        $subject = $viewer->subject() ?? throw new LogicException('Anonyme Betrachter erhalten keine Medien-URLs.');

        return $this->router->generate('media_file', [
            'token' => $this->tokens->issue($media->getId()->toRfc4122(), $subject),
            'variant' => $variant,
        ]);
    }
}
