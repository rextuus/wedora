<?php

declare(strict_types=1);

namespace App\Guest\Controller;

use App\Challenge\WelcomeMission;
use App\Guest\Entity\Guest;
use App\Guest\Form\DisplayNameType;
use App\Guest\Form\WeddingPasswordType;
use App\Guest\JoinFlow;
use App\Guest\Portrait\PortraitService;
use App\Security\Guest\GuestCookie;
use App\Security\Guest\GuestUser;
use App\Wedding\Entity\Wedding;
use App\Wedding\Repository\InvitationRepository;
use App\Wedding\Repository\WeddingRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Beitritt als Gast. Unbekannte Hochzeiten, falsche oder deaktivierte Links und
 * unbrauchbare Einladungen liefern einheitlich 404 – ohne Hinweis auf die Ursache.
 */
final class JoinController extends AbstractController
{
    public function __construct(
        private readonly WeddingRepository $weddings,
        private readonly InvitationRepository $invitations,
        private readonly JoinFlow $flow,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly PortraitService $portraits,
        private readonly WelcomeMission $welcomeMission,
    ) {}

    #[Route('/h/{slug}/join', name: 'guest_join', requirements: ['slug' => Wedding::SLUG_PATTERN], methods: ['GET'])]
    public function openLink(string $slug, Request $request): Response
    {
        $wedding = $this->activeWedding($slug);

        if ($this->getUser() instanceof GuestUser) {
            return $this->redirectToRoute('guest_home', ['slug' => $slug]);
        }

        if (!$wedding->access()->acceptsOpenLinkToken($request->query->getString('k'))) {
            throw new NotFoundHttpException();
        }

        $this->flow->startWithOpenLink($wedding);

        return $this->redirectToRoute(
            $this->flow->needsPassword($wedding) ? 'guest_join_password' : 'guest_join_name',
            [
                'slug' => $slug,
            ],
        );
    }

    #[Route(
        '/i/{token}',
        name: 'guest_invitation',
        // @mago-expect lint:no-literal-password
        requirements: ['token' => '[A-Za-z0-9_-]{16,64}'],
        methods: ['GET'],
    )]
    public function invitation(#[SensitiveParameter] string $token): Response
    {
        $invitation = $this->invitations->findOneByToken($token);

        if (null === $invitation || !$invitation->canBeUsedToJoinAt($this->now())) {
            throw new NotFoundHttpException();
        }

        $this->flow->startWithInvitation($invitation);

        return $this->redirectToRoute('guest_join_name', ['slug' => $invitation->getWedding()->getSlug()]);
    }

    #[Route(
        '/h/{slug}/join/password',
        name: 'guest_join_password',
        requirements: ['slug' => Wedding::SLUG_PATTERN],
        methods: ['GET', 'POST'],
    )]
    public function password(
        string $slug,
        Request $request,
        PasswordHasherFactoryInterface $hashers,
        #[Target('wedding_password')]
        RateLimiterFactoryInterface $weddingPasswordLimiter,
    ): Response {
        $wedding = $this->activeWedding($slug);
        if (!$this->flow->needsPassword($wedding)) {
            throw new NotFoundHttpException();
        }

        $form = $this->createForm(WeddingPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $limit = $weddingPasswordLimiter
                ->create($wedding->getId()->toRfc4122() . '|' . ($request->getClientIp() ?? 'unknown'))
                ->consume();
            if (!$limit->isAccepted()) {
                return $this->render(
                    'guest/join_password.html.twig',
                    [
                        'wedding' => $wedding,
                        'form' => $form,
                        'error' => 'Zu viele Versuche. Bitte warte ein paar Minuten.',
                    ],
                    new Response(status: Response::HTTP_TOO_MANY_REQUESTS),
                );
            }

            $hash = (string) $wedding->access()->passwordHash();
            /** @var array{password: string} $data */
            $data = $form->getData();
            if ($hashers->getPasswordHasher('wedding_password')->verify($hash, $data['password'])) {
                $this->flow->passwordVerified($wedding);

                return $this->redirectToRoute('guest_join_name', ['slug' => $slug]);
            }

            return $this->render(
                'guest/join_password.html.twig',
                [
                    'wedding' => $wedding,
                    'form' => $form,
                    'error' => 'Das Passwort stimmt leider nicht.',
                ],
                new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY),
            );
        }

        return $this->render('guest/join_password.html.twig', [
            'wedding' => $wedding,
            'form' => $form,
            'error' => null,
        ]);
    }

    #[Route(
        '/h/{slug}/join/name',
        name: 'guest_join_name',
        requirements: ['slug' => Wedding::SLUG_PATTERN],
        methods: [
            'GET',
            'POST',
        ],
    )]
    public function name(string $slug, Request $request): Response
    {
        $wedding = $this->activeWedding($slug);
        $readiness = $this->flow->readiness($wedding);
        if (!$readiness['ready']) {
            throw new NotFoundHttpException();
        }

        $form = $this->createForm(DisplayNameType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{displayName: string} $data */
            $data = $form->getData();
            [$guest, $secret] = Guest::join($wedding, $data['displayName'], $readiness['invitation']);
            $this->em->persist($guest);
            $this->em->flush();
            $this->flow->clear($wedding);

            // Nach dem Namen direkt zur ersten Aufgabe (First Drink); ohne sie das Porträt-Angebot, sonst Startseite.
            $response = match (true) {
                null !== $this->welcomeMission->firstDrink($wedding, $this->now())
                    => $this->redirectToRoute('guest_welcome', ['slug' => $slug]),
                $this->portraits->isOfferedTo($wedding) => $this->redirectToRoute('guest_portrait', [
                    'slug' => $slug,
                    'willkommen' => 1,
                ]),
                default => $this->redirectToRoute('guest_home', ['slug' => $slug]),
            };
            $response->headers->setCookie(GuestCookie::create($guest, $secret, $request));

            return $response;
        }

        return $this->render(
            'guest/join_name.html.twig',
            ['wedding' => $wedding, 'form' => $form],
            new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK),
        );
    }

    private function activeWedding(string $slug): Wedding
    {
        $wedding = $this->weddings->findOneBySlug($slug);

        if (null === $wedding || !$wedding->isActive()) {
            throw new NotFoundHttpException();
        }

        return $wedding;
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
