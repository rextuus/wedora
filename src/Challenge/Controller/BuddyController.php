<?php

declare(strict_types=1);

namespace App\Challenge\Controller;

use App\Challenge\Entity\Challenge;
use App\Challenge\PortraitBuddy;
use App\Challenge\Repository\ChallengeRepository;
use App\Guest\Controller\GuestActionController;
use App\Guest\Entity\Guest;
use App\Security\Guest\GuestUser;
use App\Wedding\Entity\Wedding;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * „Foto mit gezeichnetem Gast“: Partner zulosen oder einen anderen nehmen.
 */
#[Route('/h/{slug}/aufgaben/{id}/partner', requirements: ['slug' => Wedding::SLUG_PATTERN])]
final class BuddyController extends AbstractController
{
    public function __construct(
        private readonly PortraitBuddy $buddies,
        private readonly ChallengeRepository $challenges,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('', name: 'guest_buddy_assign', methods: ['POST'])]
    public function assign(Request $request, string $id): Response
    {
        [$guest, $challenge] = $this->resolve($request, $id);
        if (null === $this->buddies->assign($guest, $challenge)) {
            $this->addFlash('error', 'Gerade ist niemand frei – schau später wieder vorbei.');
        }

        return $this->back($guest, $challenge);
    }

    #[Route('/anderen', name: 'guest_buddy_skip', methods: ['POST'])]
    public function skip(Request $request, string $id): Response
    {
        [$guest, $challenge] = $this->resolve($request, $id);
        $this->buddies->skip($guest, $challenge);
        $this->buddies->assign($guest, $challenge);

        return $this->back($guest, $challenge);
    }

    /**
     * @return array{Guest, Challenge}
     */
    private function resolve(Request $request, string $id): array
    {
        $user = $this->getUser();
        if (!$user instanceof GuestUser) {
            throw new AccessDeniedHttpException();
        }

        if (!$this->isCsrfTokenValid(GuestActionController::CSRF_ID, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Ungültiges Formular-Token.');
        }

        $challenge = Uuid::isValid($id) ? $this->challenges->find(Uuid::fromString($id)) : null;
        if (
            null === $challenge
            || $challenge->getWedding() !== $user->guest->getWedding()
            || !$challenge->getMediaType()->isBuddy()
            || !$challenge->acceptsAt(DateTimeImmutable::createFromInterface($this->clock->now()))
        ) {
            throw new NotFoundHttpException();
        }

        return [$user->guest, $challenge];
    }

    private function back(Guest $guest, Challenge $challenge): Response
    {
        return $this->redirectToRoute('guest_challenge', [
            'slug' => $guest->getWedding()->getSlug(),
            'id' => $challenge->getId()->toRfc4122(),
        ]);
    }
}
