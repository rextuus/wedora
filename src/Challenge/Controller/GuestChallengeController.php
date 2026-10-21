<?php

declare(strict_types=1);

namespace App\Challenge\Controller;

use App\Challenge\ChallengeProgress;
use App\Challenge\ChallengeWheel;
use App\Challenge\Entity\Challenge;
use App\Challenge\PortraitBuddy;
use App\Challenge\Repository\ChallengeRepository;
use App\Gallery\GalleryFilter;
use App\Gallery\GalleryQuery;
use App\Guest\Entity\Guest;
use App\Guest\Portrait\PortraitService;
use App\Media\Entity\Media;
use App\Poll\CouplePoll;
use App\Security\Guest\GuestUser;
use App\Security\Voter\ModuleVoter;
use App\Wedding\Entity\Wedding;
use App\Wedding\ModuleType;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Aufgaben für Gäste: Liste (mit Glücksrad nur First/Last Drink und Gezogenes) und Detailseite.
 */
#[Route('/h/{slug}', requirements: ['slug' => Wedding::SLUG_PATTERN])]
final class GuestChallengeController extends AbstractController
{
    public function __construct(
        private readonly GalleryQuery $gallery,
        private readonly ChallengeRepository $challenges,
        private readonly ChallengeProgress $progress,
        private readonly ChallengeWheel $wheel,
        private readonly CouplePoll $poll,
        private readonly PortraitService $portraits,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('/aufgaben', name: 'guest_challenges', methods: ['GET'])]
    public function challenges(PortraitBuddy $buddies): Response
    {
        $guest = $this->guest();
        $wedding = $guest->getWedding();
        $this->requireModule($wedding, ModuleType::Challenges);
        $offered = $this->wheel->offered($wedding);
        $challenges = $this->wheel->visible($guest, $offered);
        $done = $this->progress->completedBy($guest, $offered);
        $pool = $this->wheel->pool($guest, $offered, $done);

        return $this->page('guest/app/challenges.html.twig', $guest, 'challenges', [
            'challenges' => $challenges,
            'completed' => ChallengeWheel::doneAmong($done, $challenges),
            'wheel' => $wedding->drawsChallengesByWheel()
                ? ['current' => $this->wheel->current($guest, $offered, $done), 'pool' => $pool]
                : null,
            'participants' => $this->progress->participants($challenges),
            'leaderboard' => $wedding->areChallengePointsEnabled() ? $this->progress->leaderboard($wedding) : [],
            'now' => $this->now(),
            'poll' => $this->poll->isVisibleIn($wedding) ? $this->poll->progressOf($guest) : null,
            'portraitOffered' => $this->portraits->isOfferedTo($wedding),
            'buddyStatus' => $buddies->statuses($guest, $challenges),
        ]);
    }

    #[Route('/aufgaben/{id}', name: 'guest_challenge', methods: ['GET'])]
    public function challenge(string $id, PortraitBuddy $buddies): Response
    {
        $guest = $this->guest();
        $this->requireModule($guest->getWedding(), ModuleType::Challenges);
        $challenge = $this->ownChallenge($guest, $id);
        if ($challenge->getMediaType()->isPoll()) {
            return $this->redirectToRoute('guest_poll', ['slug' => $guest->getWedding()->getSlug()]);
        }

        // Eigene Beiträge stehen oben (auch private und noch nicht freigegebene), unten nur die der anderen.
        $mine = $this->gallery->mineFor($guest, $challenge);
        $contributions = array_values(array_filter(
            $this->gallery->shared($guest, GalleryFilter::All, null, 200),
            static fn(Media $m): bool => (
                ($m->getChallenge()?->getId()->equals($challenge->getId()) ?? false)
                && !($m->getUploaderGuest()?->getId()->equals($guest->getId()) ?? false)
            ),
        ));

        return $this->page('guest/app/challenge.html.twig', $guest, 'challenges', [
            'challenge' => $challenge,
            'done' => array_key_exists($challenge->getId()->toRfc4122(), $this->progress->completedBy($guest, [
                $challenge,
            ])),
            'participants' => $this->progress->participants([$challenge])[$challenge->getId()->toRfc4122()] ?? 0,
            'mine' => $mine,
            'contributions' => array_slice($contributions, 0, 24),
            'now' => $this->now(),
            'buddy' => $buddies->view($guest, $challenge),
        ]);
    }

    private function guest(): Guest
    {
        $user = $this->getUser();
        if (!$user instanceof GuestUser) {
            throw new AccessDeniedHttpException();
        }

        return $user->guest;
    }

    private function ownChallenge(Guest $guest, string $id): Challenge
    {
        $challenge = Uuid::isValid($id) ? $this->challenges->find(Uuid::fromString($id)) : null;
        if (
            null === $challenge
            || $challenge->getWedding() !== $guest->getWedding()
            || !$challenge->isEnabled()
            || !$this->wheel->allows($guest, $challenge)
        ) {
            throw new NotFoundHttpException();
        }

        return $challenge;
    }

    private function requireModule(Wedding $wedding, ModuleType $type): void
    {
        if (!$this->isGranted(ModuleVoter::VIEW, $wedding->module($type))) {
            throw new NotFoundHttpException();
        }
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function page(string $template, Guest $guest, string $active, array $parameters): Response
    {
        $response = $this->render($template, [
            'guest' => $guest,
            'wedding' => $guest->getWedding(),
            'active' => $active,
            ...$parameters,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
