<?php

declare(strict_types=1);

namespace App\Wedding\Host;

use App\Security\Guest\GuestUser;
use App\Security\Voter\WeddingVoter;
use App\Wedding\Entity\Wedding;
use App\Wedding\Repository\WeddingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vertrauenshinweis: wer den Server betreibt und was mit den Fotos passiert; dazu die Bilder der Hochzeit.
 */
final class HostController extends AbstractController
{
    public function __construct(
        private readonly WeddingRepository $weddings,
        private readonly WeddingPictureStorage $pictures,
    ) {}

    /**
     * Steht schon auf der Beitrittsseite, also vor dem Beitritt sichtbar – wie die Beitrittsseite selbst
     * für alle mit dem Link der Hochzeit. Archivierte oder unbekannte Hochzeiten: 404.
     */
    #[Route(
        '/h/{slug}/join/gastgeber.jpg',
        name: 'wedding_host_photo',
        requirements: ['slug' => Wedding::SLUG_PATTERN],
        methods: ['GET'],
    )]
    public function photo(string $slug): Response
    {
        $wedding = $this->weddings->findOneBySlug($slug);
        if (null === $wedding) {
            throw new NotFoundHttpException();
        }

        $response = $this->pictureResponse($wedding, WeddingPicture::Host);
        $response->setPublic();

        return $response;
    }

    /**
     * Foto des Servers und festes Titelbild: nur für Gäste dieser Hochzeit (Vorschau der Verwaltung: SettingsController).
     */
    #[Route(
        '/h/{slug}/bild/{kind}.jpg',
        name: 'wedding_picture',
        requirements: ['slug' => Wedding::SLUG_PATTERN, 'kind' => 'server|titelbild'],
        methods: ['GET'],
    )]
    public function picture(string $slug, string $kind): Response
    {
        $wedding = $this->weddings->findOneBySlug($slug);
        if (null === $wedding || !$this->isGranted(WeddingVoter::VIEW, $wedding)) {
            throw new NotFoundHttpException();
        }

        $response = $this->pictureResponse(
            $wedding,
            'server' === $kind ? WeddingPicture::Server : WeddingPicture::Cover,
        );
        $response->setPrivate();

        return $response;
    }

    private function pictureResponse(Wedding $wedding, WeddingPicture $picture): BinaryFileResponse
    {
        $path = $this->pictures->path($wedding, $picture);
        if (null === $wedding->getPresentation()->pictureAt($picture) || !is_file($path)) {
            throw new NotFoundHttpException();
        }

        // Die URL enthält den Zeitstempel des Bildes; ein neues Bild bekommt eine neue Adresse.
        $response = new BinaryFileResponse($path, headers: ['Content-Type' => 'image/jpeg']);
        $response->setMaxAge(86_400);

        return $response;
    }

    #[Route(
        '/h/{slug}/privatsphaere',
        name: 'guest_privacy',
        requirements: ['slug' => Wedding::SLUG_PATTERN],
        methods: ['GET'],
    )]
    public function privacy(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof GuestUser) {
            throw new AccessDeniedHttpException();
        }

        return $this->render('guest/app/privacy.html.twig', [
            'guest' => $user->guest,
            'wedding' => $user->guest->getWedding(),
            'active' => 'home',
        ]);
    }
}
