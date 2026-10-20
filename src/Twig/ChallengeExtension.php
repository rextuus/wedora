<?php

declare(strict_types=1);

namespace App\Twig;

use App\Challenge\ChallengeMotif;
use App\Challenge\DrinkLock;
use App\Challenge\Entity\Challenge;
use App\Challenge\WelcomeMission;
use App\Media\Entity\Media;
use App\Security\Guest\GuestUser;
use App\Wedding\Entity\Wedding;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

final readonly class ChallengeExtension
{
    public function __construct(
        private Security $security,
        private DrinkLock $drinkLock,
        private WelcomeMission $mission,
    ) {}

    /** First oder Last Drink: nur ein Video pro Gast, ein neues ersetzt das alte. */
    #[AsTwigFunction('is_drink')]
    public function isDrink(Challenge $challenge): bool
    {
        return $this->mission->isDrink($challenge);
    }

    /** Gesperrtes Drink-Video eines anderen Gastes (solange das eigene Video zu dieser Aufgabe fehlt). */
    #[AsTwigFunction('drink_locked')]
    public function drinkLocked(Media $media): bool
    {
        $user = $this->security->getUser();

        return $user instanceof GuestUser && $this->drinkLock->isLocked($user->guest, $media);
    }

    /**
     * @return array{first: Challenge|null, last: Challenge|null}
     */
    #[AsTwigFunction('drink_challenges')]
    public function drinkChallenges(Wedding $wedding): array
    {
        return $this->mission->drinks($wedding);
    }

    #[AsTwigFunction('challenge_motif')]
    public static function motif(string $title): string
    {
        return ChallengeMotif::for($title);
    }
}
