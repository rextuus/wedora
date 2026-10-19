<?php

declare(strict_types=1);

namespace App\Guest\Controller;

use App\Guest\Entity\Guest;
use App\Guest\Portrait\PortraitRejected;
use App\Guest\Portrait\PortraitService;
use App\Guest\Portrait\PortraitStorage;
use App\Security\Guest\GuestUser;
use App\Wedding\Entity\Wedding;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gezeichnetes Porträt des Gastes: nur der Gast selbst sieht und verwaltet es.
 */
#[Route('/h/{slug}/portraet', requirements: ['slug' => Wedding::SLUG_PATTERN])]
final class PortraitController extends AbstractController
{
    public function __construct(
        private readonly PortraitService $portraits,
        private readonly PortraitStorage $storage,
    ) {}

    #[Route('', name: 'guest_portrait', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $guest = $this->guest();
        if (!$guest->hasPortrait() && !$this->portraits->isOfferedTo($guest->getWedding())) {
            throw new NotFoundHttpException();
        }

        return $this->page('guest/app/portrait.html.twig', $guest, [
            'welcome' => $request->query->getBoolean('willkommen'),
            'error' => null,
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

            $this->portraits->request($guest, $selfie);
        } catch (PortraitRejected $e) {
            return $this->page(
                'guest/app/portrait.html.twig',
                $guest,
                ['welcome' => $request->request->getBoolean('welcome'), 'error' => $e->getMessage()],
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

        return $this->page('guest/app/_portrait_frame.html.twig', $guest, [
            'portraitOffered' => $this->portraits->isOfferedTo($guest->getWedding()),
        ]);
    }

    #[Route('/bild', name: 'guest_portrait_image', methods: ['GET'])]
    public function image(Request $request): Response
    {
        $guest = $this->guest();
        $path = $this->storage->portraitPath($guest);
        if (!$guest->hasPortrait() || !is_file($path)) {
            throw new NotFoundHttpException();
        }

        $response = new BinaryFileResponse($path, headers: ['Content-Type' => 'image/png']);
        // Die URL enthält den Zeitstempel der Zeichnung, daher darf der Browser sie behalten (nur privat).
        $response->setPrivate();
        $response->setMaxAge(86_400);
        if ($request->query->getBoolean('download')) {
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'mein-portraet.png');
        }

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
