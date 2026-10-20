<?php

declare(strict_types=1);

namespace App\Guest\Controller;

use App\Guest\Codeword;
use App\Guest\CodewordRecovery;
use App\Guest\Entity\Guest;
use App\Guest\JoinFlow;
use App\Security\Guest\GuestCookie;
use App\Security\Guest\GuestUser;
use App\Wedding\Entity\Wedding;
use App\Wedding\Repository\WeddingRepository;
use SensitiveParameter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Codewort zum Wiederherstellen des Gastzugangs: anzeigen (nach dem Beitritt, später unter „Mein Album“)
 * und „Ich war schon dabei“ – Name eingeben, eigenes Codewort aus drei wählen.
 */
#[Route('/h/{slug}', requirements: ['slug' => Wedding::SLUG_PATTERN])]
final class CodewordController extends AbstractController
{
    /** Wohin es nach der Codewort-Seite weitergeht (nach dem Beitritt). */
    private const array NEXT = [
        'willkommen' => 'guest_welcome',
        'portraet' => 'guest_portrait',
        'start' => 'guest_home',
    ];

    public function __construct(
        private readonly Codeword $codewords,
    ) {}

    #[Route('/codewort', name: 'guest_codeword', methods: ['GET'])]
    public function show(Request $request, string $slug): Response
    {
        $user = $this->getUser();
        if (!$user instanceof GuestUser) {
            throw new AccessDeniedHttpException();
        }

        $next = $request->query->getString('weiter');
        $response = $this->render('guest/app/codeword.html.twig', [
            'guest' => $user->guest,
            'wedding' => $user->guest->getWedding(),
            'active' => 'album',
            'codeword' => $this->codewords->of($user->guest),
            'next' => array_key_exists($next, self::NEXT)
                ? $this->generateUrl(self::NEXT[$next], ['slug' => $slug]
                + ('portraet' === $next ? ['willkommen' => 1] : []))
                : null,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * Nur mit gültigem Beitritt (Link bzw. Einladung, ggf. Passwort) – wie die Namensseite.
     */
    #[Route('/join/wieder', name: 'guest_recover', methods: ['GET', 'POST'])]
    public function recover(
        Request $request,
        string $slug,
        WeddingRepository $weddings,
        JoinFlow $flow,
        CodewordRecovery $recovery,
    ): Response {
        $wedding = $weddings->findOneBySlug($slug);
        if (null === $wedding || !$wedding->isActive() || !$flow->readiness($wedding)['ready']) {
            throw new NotFoundHttpException();
        }

        // Name per GET (?name=…, Turbo stellt das als normale Seite dar), die Auswahl per POST.
        $name = trim($request->request->getString('name', $request->query->getString('name')));
        [$choices, $error] = $this->lookup($wedding, $name);
        $choice = $request->request->getString('codewort');

        if (null === $error && '' !== $choice) {
            $this->requireCsrf($request);
            $result = $recovery->attempt($wedding, $name, $choice, $request->getClientIp());
            if (!is_string($result)) {
                $flow->clear($wedding);

                return $this->welcomeBack($request, $result[0], $result[1]);
            }

            $error = $result;
        }

        return $this->render(
            'guest/join_recover.html.twig',
            ['wedding' => $wedding, 'name' => $name, 'choices' => $choices, 'error' => $error],
            new Response(status: null !== $error ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK),
        );
    }

    /**
     * @return array{list<string>, string|null} Auswahl und ggf. Meldung „niemand gefunden“
     */
    private function lookup(Wedding $wedding, string $name): array
    {
        if ('' === $name) {
            return [[], null];
        }

        $found = $this->codewords->choicesFor($wedding, $name);

        return (
            [] === $found['guests']
                ? [[], 'Unter diesem Namen finden wir niemanden. Schreib ihn genau so wie beim Beitritt.']
                : [$found['choices'], null]
        );
    }

    private function welcomeBack(Request $request, Guest $guest, #[SensitiveParameter] string $secret): Response
    {
        $this->addFlash('success', sprintf('Willkommen zurück, %s!', $guest->getDisplayName()));
        $response = $this->redirectToRoute('guest_home', ['slug' => $guest->getWedding()->getSlug()]);
        $response->headers->setCookie(GuestCookie::create($guest, $secret, $request));

        return $response;
    }

    private function requireCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('guest-recover', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Ungültiges Formular-Token.');
        }
    }
}
