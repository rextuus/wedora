<?php

declare(strict_types=1);

namespace App\Poll\Controller;

use App\Guest\Controller\GuestActionController;
use App\Guest\Entity\Guest;
use App\Poll\CouplePoll;
use App\Poll\Entity\PollQuestion;
use App\Poll\PollChoice;
use App\Poll\PollRejected;
use App\Poll\Repository\PollQuestionRepository;
use App\Security\Guest\GuestUser;
use App\Wedding\CoupleNames;
use App\Wedding\Entity\Wedding;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Umfrage für Gäste: abstimmen, umentscheiden, Ergebnis nach der eigenen Stimme.
 */
#[Route('/h/{slug}/wer-von-beiden', requirements: ['slug' => Wedding::SLUG_PATTERN])]
final class PollController extends AbstractController
{
    public function __construct(
        private readonly CouplePoll $game,
        private readonly PollQuestionRepository $questions,
    ) {}

    #[Route('', name: 'guest_poll', methods: ['GET'])]
    public function show(ClockInterface $clock): Response
    {
        $guest = $this->guest();
        $wedding = $guest->getWedding();
        if (!$this->game->isVisibleIn($wedding)) {
            throw new NotFoundHttpException();
        }

        $questions = $this->questions->forWedding($wedding);
        $response = $this->render('guest/app/poll.html.twig', [
            'guest' => $guest,
            'wedding' => $wedding,
            'active' => 'challenges',
            'questions' => $questions,
            'names' => CoupleNames::of($wedding),
            'votes' => $this->game->votesBy($guest, $questions),
            'tally' => $this->game->tally($questions),
            'canVote' => $wedding->isActive() && $this->game->isOpenIn($wedding),
            'challenge' => $this->game->challenge($wedding),
            'done' => count($questions) === count($this->game->votesBy($guest, $questions)),
            'now' => DateTimeImmutable::createFromInterface($clock->now()),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/{id}', name: 'guest_poll_vote', methods: ['POST'])]
    public function vote(Request $request, string $id): Response
    {
        $guest = $this->guest();
        if (!$this->isCsrfTokenValid(GuestActionController::CSRF_ID, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Ungültiges Formular-Token.');
        }

        $choice = PollChoice::tryFrom($request->request->getString('choice')) ?? throw new BadRequestHttpException(
            'Unbekannte Auswahl.',
        );

        try {
            $this->game->vote($guest, $this->question($guest, $id), $choice);
        } catch (PollRejected $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('guest_poll', [
            'slug' => $guest->getWedding()->getSlug(),
            '_fragment' => 'frage-' . $id,
        ]);
    }

    private function question(Guest $guest, string $id): PollQuestion
    {
        $question = Uuid::isValid($id) ? $this->questions->find(Uuid::fromString($id)) : null;
        if (null === $question || $question->getWedding() !== $guest->getWedding()) {
            throw new NotFoundHttpException();
        }

        return $question;
    }

    private function guest(): Guest
    {
        $user = $this->getUser();
        if (!$user instanceof GuestUser) {
            throw new AccessDeniedHttpException();
        }

        return $user->guest;
    }
}
