<?php

declare(strict_types=1);

namespace App\Challenge\Controller;

use App\Challenge\DrinkComparison;
use App\Security\Guest\GuestUser;
use App\Wedding\Entity\Wedding;
use App\Wedding\ModuleType;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class DrinkComparisonController extends AbstractController
{
    public function __construct(
        private readonly DrinkComparison $comparison,
        private readonly ClockInterface $clock,
    ) {}

    #[Route(
        '/h/{slug}/aufgaben/vorher-nachher',
        name: 'guest_drink_comparison',
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
        $challenges = $this->comparison->challenges($guest);
        if (null === $challenges || !$wedding->module(ModuleType::Challenges)->isEnabled()) {
            throw new NotFoundHttpException();
        }

        $response = $this->render('guest/app/drink_comparison.html.twig', [
            'guest' => $guest,
            'wedding' => $wedding,
            'active' => 'challenges',
            'firstDrink' => $challenges[0],
            'lastDrink' => $challenges[1],
            'own' => $this->comparison->own($guest),
            'others' => $this->comparison->others($guest),
            'now' => DateTimeImmutable::createFromInterface($this->clock->now()),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
