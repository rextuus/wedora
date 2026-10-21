<?php

declare(strict_types=1);

namespace App\Challenge\Controller;

use App\Challenge\ChallengeWheel;
use App\Guest\Controller\GuestActionController;
use App\Security\Guest\GuestUser;
use App\Security\Voter\ModuleVoter;
use App\Wedding\Entity\Wedding;
use App\Wedding\ModuleType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Glücksrad drehen. Das Rad auf der Seite dreht per JavaScript und hält auf der Aufgabe, die der Server
 * gezogen hat (JSON); ohne JavaScript geht es per Weiterleitung direkt zur Aufgabe.
 */
final class WheelController extends AbstractController
{
    #[Route(
        '/h/{slug}/aufgaben/gluecksrad',
        name: 'guest_challenge_spin',
        requirements: ['slug' => Wedding::SLUG_PATTERN],
        methods: ['POST'],
    )]
    public function spin(Request $request, ChallengeWheel $wheel): Response
    {
        $user = $this->getUser();
        if (!$user instanceof GuestUser) {
            throw new AccessDeniedHttpException();
        }

        if (!$this->isCsrfTokenValid(GuestActionController::CSRF_ID, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Ungültiges Formular-Token.');
        }

        $wedding = $user->guest->getWedding();
        if (!$this->isGranted(ModuleVoter::CONTRIBUTE, $wedding->module(ModuleType::Challenges))) {
            throw new NotFoundHttpException();
        }

        $challenge = $wheel->spin($user->guest);
        if (null === $challenge) {
            if ('json' === $request->getPreferredFormat()) {
                return $this->json(['challenge' => null], Response::HTTP_CONFLICT);
            }

            $this->addFlash('error', 'Gerade gibt es nichts zu ziehen.');

            return $this->redirectToRoute('guest_challenges', ['slug' => $wedding->getSlug()]);
        }

        $url = $this->generateUrl('guest_challenge', [
            'slug' => $wedding->getSlug(),
            'id' => $challenge->getId()->toRfc4122(),
        ]);

        return (
            'json' === $request->getPreferredFormat()
                ? $this->json(['challenge' => [
                    'id' => $challenge->getId()->toRfc4122(),
                    'title' => $challenge->getTitle(),
                    'url' => $url,
                ]])
                : $this->redirect($url)
        );
    }
}
