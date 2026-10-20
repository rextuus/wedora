<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Account\WeddingPermission;
use App\Poll\CouplePoll;
use App\Poll\Entity\PollQuestion;
use App\Poll\PollTemplates;
use App\Poll\Repository\PollQuestionRepository;
use App\Wedding\CoupleNames;
use App\Wedding\Entity\Wedding;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Fragen der Aufgabe „Wer von beiden?“ verwalten: anlegen, löschen, Ergebnisse, Ergebnisse für Gäste.
 * Sichtbarkeit, Punkte und Zeitfenster stellt man wie bei jeder Aufgabe unter „Aufgaben“ ein.
 */
#[IsGranted('ROLE_USER')]
#[Route('/admin/h/{slug}/umfrage', requirements: ['slug' => Wedding::SLUG_PATTERN])]
final class PollAdminController extends AdminController
{
    public function __construct(
        private readonly PollQuestionRepository $questions,
        private readonly CouplePoll $game,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'admin_poll', methods: ['GET'])]
    public function index(string $slug): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::Configure);
        $questions = $this->questions->forWedding($wedding);
        $existing = array_map(static fn(PollQuestion $q): string => $q->getText(), $questions);

        return $this->page('admin/poll.html.twig', $wedding, 'challenges', [
            'questions' => $questions,
            'tally' => $this->game->tally($questions),
            'names' => CoupleNames::of($wedding),
            'templates' => array_values(array_diff(PollTemplates::QUESTIONS, $existing)),
            'visible' => $this->game->isVisibleIn($wedding),
            'challenge' => $this->game->challenge($wedding),
        ]);
    }

    #[Route('/einstellungen', name: 'admin_poll_settings', methods: ['POST'])]
    public function settings(Request $request, string $slug): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::Configure);
        $this->requireCsrf($request);
        $wedding->showPollLiveResults($request->request->has('live_results'));
        $this->em->flush();
        $this->addFlash('success', 'Gespeichert.');

        return $this->redirectToRoute('admin_poll', ['slug' => $slug]);
    }

    #[Route('/fragen', name: 'admin_poll_create', methods: ['POST'])]
    public function create(Request $request, string $slug): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::Configure);
        $this->requireCsrf($request);

        try {
            $question = new PollQuestion($wedding, $request->request->getString('text'));
        } catch (InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_poll', ['slug' => $slug]);
        }

        $question->moveTo(count($this->questions->forWedding($wedding)));
        $this->em->persist($question);
        $this->em->flush();

        return $this->redirectToRoute('admin_poll', ['slug' => $slug]);
    }

    /** Frage samt Stimmen löschen. */
    #[Route('/fragen/{id}', name: 'admin_poll_update', methods: ['POST'])]
    public function update(Request $request, string $slug, string $id): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::Configure);
        $this->requireCsrf($request);
        $question = Uuid::isValid($id) ? $this->questions->find(Uuid::fromString($id)) : null;
        if (null === $question || $question->getWedding() !== $wedding) {
            throw new NotFoundHttpException();
        }

        if ('loeschen' === $request->request->getString('action')) {
            $this->em->remove($question);
            $this->em->flush();
        }

        return $this->redirectToRoute('admin_poll', ['slug' => $slug]);
    }
}
