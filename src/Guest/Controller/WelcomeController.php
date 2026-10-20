<?php

declare(strict_types=1);

namespace App\Guest\Controller;

use App\Challenge\WelcomeMission;
use App\Security\Guest\GuestUser;
use App\Wedding\Entity\Wedding;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Erste Station nach dem Beitritt: Hinweis auf First Drink / Last Drink.
 */
final class WelcomeController extends AbstractController
{
    public function __construct(
        private readonly WelcomeMission $mission,
        private readonly ClockInterface $clock,
    ) {}

    #[Route(
        '/h/{slug}/willkommen',
        name: 'guest_welcome',
        requirements: ['slug' => Wedding::SLUG_PATTERN],
        methods: ['GET'],
    )]
    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof GuestUser) {
            throw new AccessDeniedHttpException();
        }

        $guest = $user->guest;
        $wedding = $guest->getWedding();
        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $firstDrink = $this->mission->firstDrink($wedding, $now);
        if (null === $firstDrink) {
            return $this->redirectToRoute('guest_home', ['slug' => $wedding->getSlug()]);
        }

        $response = $this->render('guest/app/welcome.html.twig', [
            'guest' => $guest,
            'wedding' => $wedding,
            'active' => 'home',
            'firstDrink' => $firstDrink,
            'lastDrink' => $this->mission->lastDrink($wedding, $now),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
