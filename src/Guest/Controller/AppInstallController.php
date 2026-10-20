<?php

declare(strict_types=1);

namespace App\Guest\Controller;

use App\Guest\AppReentry;
use App\Media\Controller\GuestApiController;
use App\Security\Guest\GuestCookie;
use App\Security\Guest\GuestUser;
use App\Wedding\Entity\Wedding;
use App\Wedding\Repository\WeddingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hochzeit als App auf dem Startbildschirm.
 *
 * Android teilt die Cookies mit der installierten App. Auf dem iPhone hat eine Web-App vom Home-Bildschirm
 * einen eigenen, leeren Cookie-Speicher: Dafür erzeugt der Gast auf Knopfdruck einen Einmal-Link
 * (15 Minuten, einmal einlösbar, Code nur im Fragment #t=…, das nie an den Server geht), den Safari als
 * Adresse des Home-Bildschirm-Symbols übernimmt. Beim ersten Start tauscht die App ihn gegen ein Gast-Cookie.
 */
#[Route('/h/{slug}', requirements: ['slug' => Wedding::SLUG_PATTERN])]
final class AppInstallController extends AbstractController
{
    public function __construct(
        private readonly WeddingRepository $weddings,
        private readonly AppReentry $reentry,
    ) {}

    /** Ohne start_url: Browser nehmen die aktuelle Adresse (auf dem iPhone ggf. den Einmal-Link). */
    #[Route('/join/manifest.webmanifest', name: 'guest_app_manifest', methods: ['GET'])]
    public function manifest(string $slug, Packages $assets): JsonResponse
    {
        $wedding = $this->wedding($slug);

        return new JsonResponse([
            'name' => $wedding->getTitle(),
            'short_name' => mb_strimwidth($wedding->getTitle(), 0, 18, '…'),
            'description' => 'Fotos, Videos und Aufgaben der Hochzeit',
            'lang' => 'de',
            'scope' => GuestCookie::path($slug) . '/',
            'display' => 'standalone',
            'background_color' => '#fbf9f6',
            'theme_color' => '#fbf9f6',
            'icons' => [
                [
                    'src' => $assets->getUrl('images/app-icon-192.png'),
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
                [
                    'src' => $assets->getUrl('images/app-icon-512.png'),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
            ],
        ], headers: ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
    }

    /** Einmal-Link für das Home-Bildschirm-Symbol (nur angemeldete Gäste, nur vom eigenen Frontend). */
    #[Route('/app-link', name: 'guest_app_link', methods: ['POST'])]
    public function link(Request $request, string $slug): JsonResponse
    {
        $user = $this->getUser();
        $credential = GuestCookie::parse((string) $request->cookies->get(GuestCookie::NAME, ''));
        if (!$user instanceof GuestUser || null === $credential || !GuestApiController::isSameOrigin($request)) {
            return self::json403('Nicht erlaubt.');
        }

        $token = $this->reentry->issue($user->guest, $credential[1]);

        return new JsonResponse([
            'url' => $this->generateUrl('guest_app_reentry', ['slug' => $slug]) . '#t=' . $token,
            'validMinutes' => AppReentry::VALID_MINUTES,
        ], headers: ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/join/app', name: 'guest_app_reentry', methods: ['GET'])]
    public function reentry(string $slug): Response
    {
        $response = $this->render('guest/app_reentry.html.twig', ['wedding' => $this->wedding($slug)]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /** Löst den Einmal-Code ein und setzt das Gast-Cookie (nur vom eigenen Frontend). */
    #[Route('/join/app', name: 'guest_app_reentry_login', methods: ['POST'])]
    public function login(Request $request, string $slug): JsonResponse
    {
        $wedding = $this->wedding($slug);
        if (!GuestApiController::isSameOrigin($request)) {
            return self::json403('Ungültige Herkunft der Anfrage.');
        }

        $redeemed = $this->reentry->redeem($wedding, $request->request->getString('token'));
        if (null === $redeemed) {
            return self::json403('Dieser Link ist abgelaufen oder wurde schon benutzt.');
        }

        [$guest, $secret] = $redeemed;
        $response = new JsonResponse(['redirect' => $this->generateUrl('guest_home', ['slug' => $slug])]);
        $response->headers->setCookie(GuestCookie::create($guest, $secret, $request));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private static function json403(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], Response::HTTP_FORBIDDEN, [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Auch archivierte Hochzeiten: Gäste dürfen dort weiter ansehen. */
    private function wedding(string $slug): Wedding
    {
        return $this->weddings->findOneBySlug($slug) ?? throw new NotFoundHttpException();
    }
}
