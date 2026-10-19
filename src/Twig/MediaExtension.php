<?php

declare(strict_types=1);

namespace App\Twig;

use App\Media\Access\MediaUrlGenerator;
use App\Media\Entity\Media;
use App\Security\ViewerResolver;
use DateTimeImmutable;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

final readonly class MediaExtension
{
    public function __construct(
        private MediaUrlGenerator $urls,
        private ViewerResolver $viewers,
        private Security $security,
    ) {}

    /**
     * Kurzlebige, an den aktuellen Betrachter gebundene URL (Prüfung erfolgt beim Abruf erneut).
     */
    #[AsTwigFunction('media_url')]
    public function mediaUrl(Media $media, string $variant = 'image/thumb'): string
    {
        return $this->urls->generate($this->viewers->fromSecurityUser($this->security->getUser()), $media, $variant);
    }

    #[AsTwigFilter('duration')]
    public static function duration(?float $seconds): string
    {
        $seconds = (int) round($seconds ?? 0);

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /**
     * „vor 6 Minuten“, „vor 2 Stunden“, sonst Datum/Uhrzeit.
     */
    #[AsTwigFilter('ago')]
    public static function ago(DateTimeImmutable $at): string
    {
        $diff = max(0, new DateTimeImmutable()->getTimestamp() - $at->getTimestamp());

        return match (true) {
            $diff < 60 => 'gerade eben',
            $diff < 3600 => sprintf('vor %d Minute%s', intdiv($diff, 60), intdiv($diff, 60) === 1 ? '' : 'n'),
            $diff < 86_400 => sprintf('vor %d Stunde%s', intdiv($diff, 3600), intdiv($diff, 3600) === 1 ? '' : 'n'),
            default => $at->format('d.m.Y, H:i') . ' Uhr',
        };
    }
}
