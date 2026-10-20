<?php

declare(strict_types=1);

namespace App\Guest\Controller;

use App\Guest\Entity\Guest;
use App\Guest\Portrait\PortraitService;
use App\Guest\Portrait\PortraitWall;
use App\Security\Guest\GuestUser;
use App\Wedding\Entity\Wedding;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gästewand „Wer ist alles da?“: freigegebene Porträts mit Namen. Gesperrt, bis man das eigene zeigt.
 */
#[Route('/h/{slug}/gaeste', requirements: ['slug' => Wedding::SLUG_PATTERN])]
final class PortraitWallController extends AbstractController
{
    public function __construct(
        private readonly PortraitWall $wall,
        private readonly PortraitService $portraits,
    ) {}

    #[Route('', name: 'guest_wall', methods: ['GET'])]
    public function show(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof GuestUser) {
            throw new AccessDeniedHttpException();
        }

        $guest = $user->guest;
        $wedding = $guest->getWedding();
        if (!$this->wall->isOfferedIn($wedding)) {
            throw new NotFoundHttpException();
        }

        $guests = $this->wall->guests($wedding);
        $unlocked = $guest->hasPortrait() && $guest->isPortraitShared();
        $response = $this->render('guest/app/wall.html.twig', [
            'guest' => $guest,
            'wedding' => $wedding,
            'active' => 'home',
            'unlocked' => $unlocked,
            // Gesperrt nur die Anzahl, keine Namen.
            'others' => $unlocked
                ? array_values(array_filter($guests, static fn(Guest $g): bool => $g !== $guest))
                : [],
            'count' => count(array_filter($guests, static fn(Guest $g): bool => $g !== $guest)),
            'portraitOffered' => $this->portraits->isOfferedTo($wedding),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
