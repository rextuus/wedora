<?php

declare(strict_types=1);

namespace App\Guest\Controller;

use App\Ai\AiBudget;
use App\Guest\Entity\Guest;
use App\Guest\Portrait\PortraitGadget;
use App\Guest\Portrait\PortraitQuestions;
use App\Guest\Portrait\PortraitRejected;
use App\Guest\Portrait\PortraitService;
use App\Guest\Portrait\PortraitStorage;
use App\Guest\Portrait\PortraitStyle;
use App\Guest\Portrait\PortraitWall;
use App\Guest\Repository\GuestRepository;
use App\Security\Guest\GuestUser;
use App\Wedding\CoupleNames;
use App\Wedding\Entity\Wedding;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Gezeichnetes Porträt des Gastes: Der Gast verwaltet es selbst und entscheidet, ob andere Gäste es sehen
 * (Gästewand, siehe PortraitWall).
 */
#[Route('/h/{slug}/portraet', requirements: ['slug' => Wedding::SLUG_PATTERN])]
final class PortraitController extends AbstractController
{
    public function __construct(
        private readonly PortraitService $portraits,
        private readonly PortraitStorage $storage,
        private readonly AiBudget $budget,
        private readonly PortraitWall $wall,
        private readonly GuestRepository $guests,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'guest_portrait', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $guest = $this->guest();
        $this->portraits->expireIfStale($guest);
        if (!$guest->hasPortrait() && !$this->portraits->isOfferedTo($guest->getWedding())) {
            throw new NotFoundHttpException();
        }

        return $this->page('guest/app/portrait.html.twig', $guest, [
            'welcome' => $request->query->getBoolean('willkommen'),
            'error' => null,
            'question' => self::question($guest, null),
            'exhausted' => !$this->budget->allows(),
        ]);
    }

    #[Route('', name: 'guest_portrait_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $guest = $this->guest();
        $this->requireCsrf($request);

        try {
            if (!$request->request->has('consent')) {
                throw new PortraitRejected('Bitte bestätige, dass dein Foto dafür an Google übertragen werden darf.');
            }

            // @mago-expect analysis:mixed-assignment
            $selfie = $request->files->get('selfie');
            if (!$selfie instanceof UploadedFile) {
                throw new PortraitRejected('Bitte zuerst ein Foto aufnehmen.');
            }

            $wish = $request->request->has('style')
                ? PortraitStyle::tryFrom($request->request->getString('style')) ?? throw new PortraitRejected(
                    'Unbekannter Zeichenstil.',
                )
                : null;
            $answer = $request->request->has('answer')
                ? [$request->request->getString('question'), $request->request->getString('answer')]
                : null;
            $this->portraits->request($guest, $selfie, $wish, $answer);
        } catch (PortraitRejected $e) {
            return $this->page(
                'guest/app/portrait.html.twig',
                $guest,
                [
                    'welcome' => $request->request->getBoolean('welcome'),
                    'error' => $e->getMessage(),
                    'question' => self::question($guest, $request->request->getString('question')),
                    'exhausted' => !$this->budget->allows(),
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->redirectToRoute('guest_home', ['slug' => $guest->getWedding()->getSlug()]);
    }

    /** Teil der Startseite; lädt sich neu, solange gezeichnet wird. */
    #[Route('/rahmen', name: 'guest_portrait_frame', methods: ['GET'])]
    public function frame(): Response
    {
        $guest = $this->guest();
        $this->portraits->expireIfStale($guest);

        return $this->page('guest/app/_portrait_frame.html.twig', $guest, [
            'portraitOffered' => $this->portraits->isOfferedTo($guest->getWedding()),
        ]);
    }

    #[Route('/bild', name: 'guest_portrait_image', methods: ['GET'])]
    public function image(Request $request): Response
    {
        $guest = $this->guest();
        $response = $this->picture($guest, $guest);
        if ($request->query->getBoolean('download')) {
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'mein-portraet.png');
        }

        return $response;
    }

    /** Porträt eines anderen Gastes – nur, wenn beide ihr Porträt freigegeben haben. */
    #[Route('/von/{id}', name: 'guest_portrait_of', methods: ['GET'])]
    public function imageOf(string $id): Response
    {
        $viewer = $this->guest();
        $owner = Uuid::isValid($id) ? $this->guests->find(Uuid::fromString($id)) : null;
        if (null === $owner || !$this->wall->canSee($viewer, $owner)) {
            throw new NotFoundHttpException();
        }

        return $this->picture($owner, $viewer);
    }

    /** Porträt auf der Gästewand zeigen oder wieder privat machen. */
    #[Route('/freigabe', name: 'guest_portrait_share', methods: ['POST'])]
    public function share(Request $request): Response
    {
        $guest = $this->guest();
        $this->requireCsrf($request);
        $shared = $request->request->getBoolean('shared') && $guest->hasPortrait();
        $guest->sharePortrait($shared);
        $this->em->flush();
        $this->addFlash(
            'success',
            $shared ? 'Dein Porträt ist jetzt auf der Gästewand.' : 'Dein Porträt sehen jetzt wieder nur du.',
        );

        $back = $request->request->getString('zurueck');

        return (
            'wand' === $back
                ? $this->redirectToRoute('guest_wall', ['slug' => $guest->getWedding()->getSlug()])
                : $this->redirectToRoute('guest_portrait', ['slug' => $guest->getWedding()->getSlug()])
        );
    }

    private function picture(Guest $owner, Guest $viewer): BinaryFileResponse
    {
        $path = $this->storage->portraitPath($owner);
        if (!$owner->hasPortrait() || !is_file($path)) {
            throw new NotFoundHttpException();
        }

        $response = new BinaryFileResponse($path, headers: ['Content-Type' => 'image/png']);
        // Die URL enthält den Zeitstempel der Zeichnung, daher darf der Browser sie behalten (nur privat).
        $response->setPrivate();
        $response->setMaxAge($owner === $viewer ? 86_400 : 600);

        return $response;
    }

    #[Route('/loeschen', name: 'guest_portrait_delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $guest = $this->guest();
        $this->requireCsrf($request);
        $this->portraits->remove($guest);
        $this->addFlash('success', 'Dein Porträt wurde gelöscht.');

        return $this->redirectToRoute('guest_home', ['slug' => $guest->getWedding()->getSlug()]);
    }

    /**
     * Frage beim Selfie (nur wenn Accessoires an sind); nach einem Fehler dieselbe wie zuvor.
     *
     * @return array{key: string, question: string, answers: array<string, array{label: string, gadget: PortraitGadget}>}|null
     */
    private static function question(Guest $guest, ?string $previous): ?array
    {
        $wedding = $guest->getWedding();
        if (!$wedding->arePortraitGadgetsEnabled()) {
            return null;
        }

        $names = CoupleNames::of($wedding);
        $all = PortraitQuestions::all($names);
        $key = null !== $previous && array_key_exists($previous, $all)
            ? $previous
            : PortraitQuestions::randomKey($names);

        return ['key' => $key, ...$all[$key]];
    }

    private function guest(): Guest
    {
        $user = $this->getUser();
        if (!$user instanceof GuestUser) {
            throw new AccessDeniedHttpException();
        }

        return $user->guest;
    }

    private function requireCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(GuestActionController::CSRF_ID, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Ungültiges Formular-Token.');
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function page(string $template, Guest $guest, array $parameters, int $status = Response::HTTP_OK): Response
    {
        $response = $this->render(
            $template,
            ['guest' => $guest, 'wedding' => $guest->getWedding(), 'active' => 'home', ...$parameters],
            new Response(status: $status),
        );
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
