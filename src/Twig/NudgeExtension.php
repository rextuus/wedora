<?php

declare(strict_types=1);

namespace App\Twig;

use App\Guest\Entity\Guest;
use App\Guest\Nudge;
use Twig\Attribute\AsTwigFunction;

final readonly class NudgeExtension
{
    public function __construct(
        private Nudge $nudge,
    ) {}

    /**
     * @return array{mode: string, threshold: int, recent: int}
     */
    #[AsTwigFunction('nudge_settings')]
    public function settings(Guest $guest): array
    {
        return $this->nudge->settingsFor($guest);
    }
}
