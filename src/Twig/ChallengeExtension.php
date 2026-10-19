<?php

declare(strict_types=1);

namespace App\Twig;

use App\Challenge\ChallengeMotif;
use Twig\Attribute\AsTwigFunction;

final class ChallengeExtension
{
    #[AsTwigFunction('challenge_motif')]
    public static function motif(string $title): string
    {
        return ChallengeMotif::for($title);
    }
}
