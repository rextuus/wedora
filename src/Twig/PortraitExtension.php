<?php

declare(strict_types=1);

namespace App\Twig;

use App\Guest\Entity\Guest;
use App\Guest\Portrait\PortraitWall;
use App\Security\Guest\GuestUser;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

final readonly class PortraitExtension
{
    public function __construct(
        private Security $security,
        private PortraitWall $wall,
    ) {}

    /** Darf der angemeldete Gast dieses Porträt sehen (eigenes oder beide freigegeben)? */
    #[AsTwigFunction('portrait_visible')]
    public function visible(?Guest $owner): bool
    {
        $user = $this->security->getUser();

        return null !== $owner && $user instanceof GuestUser && $this->wall->canSee($user->guest, $owner);
    }
}
