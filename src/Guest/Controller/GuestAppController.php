<?php

declare(strict_types=1);

namespace App\Guest\Controller;

use App\Challenge\ChallengeProgress;
use App\Challenge\Entity\Challenge;
use App\Challenge\Repository\ChallengeRepository;
use App\Gallery\GalleryFilter;
use App\Gallery\GalleryQuery;
use App\Gallery\MagazineLayout;
use App\Guest\Entity\Guest;
use App\Guest\Portrait\PortraitService;
use App\Guestbook\GuestbookFeed;
use App\Media\Entity\Media;
use App\Media\MediaType;
use App\Media\Repository\MediaRepository;
use App\Security\Guest\GuestUser;
use App\Security\Voter\MediaVoter;
use App\Security\Voter\ModuleVoter;
use App\Social\LikeService;
use App\Social\ReportReason;
use App\Wedding\Entity\Wedding;
use App\Wedding\ModuleType;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Lesende Seiten der Gast-App. Alle Listen kommen aus GalleryQuery/GuestbookFeed,
 * deren Bedingungen der Rechteprüfung entsprechen.
 */
#[Route('/h/{slug}', requirements: ['slug' => Wedding::SLUG_PATTERN])]
final class GuestAppController extends AbstractController
{
    public function __construct(
        private readonly GalleryQuery $gallery,
        private readonly LikeService $likes,
        private readonly ChallengeRepository $challenges,
        private readonly ChallengeProgress $progress,
        private readonly MediaRepository $media,
        private readonly ClockInterface $clock,
        private readonly PortraitService $portraits,
    ) {}

    #[Route('', name: 'guest_home', methods: ['GET'])]
    public function home(): Response
    {
        $guest = $this->guest();
        $wedding = $guest->getWedding();
        $challenges = $this->moduleOn($wedding, ModuleType::Challenges)
            ? $this->challenges->forWedding($wedding, true)
            : [];
        $done = $this->progress->completedBy($guest, $challenges);
        $now = $this->now();
        $active = null;
        foreach ($challenges as $challenge) {
            if ($challenge->acceptsAt($now) && !array_key_exists($challenge->getId()->toRfc4122(), $done)) {
                $active = $challenge;
                break;
            }
        }

        $fresh = $this->gallery->shared($guest, GalleryFilter::All, null, 4);

        return $this->page('guest/app/home.html.twig', $guest, 'home', [
            'challenges' => $challenges,
            'activeChallenge' => $active,
            'completed' => $done,
            'participants' => $this->progress->participants($challenges),
            'fresh' => $fresh,
            'likeCounts' => $this->likes->counts($fresh),
            'liked' => $this->likes->likedBy($guest, $fresh),
            'now' => $now,
            'canUpload' => $this->isGranted(ModuleVoter::CONTRIBUTE, $wedding->module(ModuleType::Gallery)),
            'portraitOffered' => $this->portraits->isOfferedTo($wedding),
        ]);
    }

    #[Route('/galerie', name: 'guest_gallery', methods: ['GET'])]
    public function gallery(Request $request): Response
    {
        $guest = $this->guest();
        $filter = GalleryFilter::tryFrom($request->query->getString('filter', 'alle')) ?? GalleryFilter::All;
        $before = Uuid::isValid($request->query->getString('vor'))
            ? Uuid::fromString($request->query->getString('vor'))
            : null;
        $items = $this->gallery->shared($guest, $filter, $before);
        $likeCounts = $this->likes->counts($items);
        $next = count($items) === GalleryQuery::PAGE_SIZE ? end($items)->getId()->toRfc4122() : null;

        $template = null !== $before ? 'guest/app/_gallery_page.html.twig' : 'guest/app/gallery.html.twig';

        return $this->page($template, $guest, 'gallery', [
            'items' => MagazineLayout::arrange($items, $likeCounts),
            'filter' => $filter,
            'filters' => GalleryFilter::cases(),
            'likeCounts' => $likeCounts,
            'liked' => $this->likes->likedBy($guest, $items),
            'next' => $next,
            'canUpload' => $this->isGranted(ModuleVoter::CONTRIBUTE, $guest->getWedding()->module(ModuleType::Gallery)),
        ]);
    }

    #[Route('/m/{id}', name: 'guest_media', methods: ['GET'])]
    public function detail(string $id): Response
    {
        $guest = $this->guest();
        $media = $this->visibleMedia($id);

        return $this->page('guest/app/detail.html.twig', $guest, 'gallery', [
            'media' => $media,
            'own' => $media->getUploaderGuest()?->getId()->equals($guest->getId()) ?? false,
            'likeCount' => $this->likes->counts([$media])[$media->getId()->toRfc4122()] ?? 0,
            'isLiked' => array_key_exists($media->getId()->toRfc4122(), $this->likes->likedBy($guest, [$media])),
            'reasons' => ReportReason::cases(),
            'canDelete' => $this->isGranted(MediaVoter::DELETE, $media),
        ]);
    }

    #[Route('/album', name: 'guest_album', methods: ['GET'])]
    public function album(): Response
    {
        $guest = $this->guest();
        $items = $this->gallery->mine($guest);
        $challenges = $this->moduleOn($guest->getWedding(), ModuleType::Challenges)
            ? $this->challenges->forWedding($guest->getWedding(), true)
            : [];

        return $this->page('guest/app/album.html.twig', $guest, 'album', [
            'items' => $items,
            'photos' => count(array_filter($items, static fn(Media $m): bool => MediaType::Photo === $m->getType())),
            'videos' => count(array_filter($items, static fn(Media $m): bool => MediaType::Video === $m->getType())),
            'challenges' => $challenges,
            'completed' => $this->progress->completedBy($guest, $challenges),
        ]);
    }

    #[Route('/aufgaben', name: 'guest_challenges', methods: ['GET'])]
    public function challenges(): Response
    {
        $guest = $this->guest();
        $wedding = $guest->getWedding();
        $this->requireModule($wedding, ModuleType::Challenges);
        $challenges = $this->challenges->forWedding($wedding, true);

        return $this->page('guest/app/challenges.html.twig', $guest, 'challenges', [
            'challenges' => $challenges,
            'completed' => $this->progress->completedBy($guest, $challenges),
            'participants' => $this->progress->participants($challenges),
            'leaderboard' => $wedding->areChallengePointsEnabled() ? $this->progress->leaderboard($wedding) : [],
            'now' => $this->now(),
        ]);
    }

    #[Route('/aufgaben/{id}', name: 'guest_challenge', methods: ['GET'])]
    public function challenge(string $id): Response
    {
        $guest = $this->guest();
        $this->requireModule($guest->getWedding(), ModuleType::Challenges);
        $challenge = $this->ownChallenge($guest, $id);
        $contributions = array_values(array_filter(
            $this->gallery->shared($guest, GalleryFilter::All, null, 200),
            static fn(Media $m): bool => $m->getChallenge()?->getId()->equals($challenge->getId()) ?? false,
        ));

        return $this->page('guest/app/challenge.html.twig', $guest, 'challenges', [
            'challenge' => $challenge,
            'done' => array_key_exists($challenge->getId()->toRfc4122(), $this->progress->completedBy($guest, [
                $challenge,
            ])),
            'participants' => $this->progress->participants([$challenge])[$challenge->getId()->toRfc4122()] ?? 0,
            'contributions' => array_slice($contributions, 0, 24),
            'now' => $this->now(),
        ]);
    }

    #[Route('/gaestebuch', name: 'guest_guestbook', methods: ['GET'])]
    public function guestbook(GuestbookFeed $feed): Response
    {
        $guest = $this->guest();
        $this->requireModule($guest->getWedding(), ModuleType::Guestbook);

        return $this->page('guest/app/guestbook.html.twig', $guest, 'guestbook', [
            'items' => $feed->forGuest($guest),
            'canWrite' => $this->isGranted(
                ModuleVoter::CONTRIBUTE,
                $guest->getWedding()->module(ModuleType::Guestbook),
            ),
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

    private function visibleMedia(string $id): Media
    {
        $media = Uuid::isValid($id) ? $this->media->find(Uuid::fromString($id)) : null;
        if (null === $media || !$this->isGranted(MediaVoter::VIEW, $media)) {
            throw new NotFoundHttpException();
        }

        return $media;
    }

    private function ownChallenge(Guest $guest, string $id): Challenge
    {
        $challenge = Uuid::isValid($id) ? $this->challenges->find(Uuid::fromString($id)) : null;
        if (null === $challenge || $challenge->getWedding() !== $guest->getWedding() || !$challenge->isEnabled()) {
            throw new NotFoundHttpException();
        }

        return $challenge;
    }

    private function moduleOn(Wedding $wedding, ModuleType $type): bool
    {
        return $this->isGranted(ModuleVoter::VIEW, $wedding->module($type));
    }

    private function requireModule(Wedding $wedding, ModuleType $type): void
    {
        if (!$this->moduleOn($wedding, $type)) {
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
