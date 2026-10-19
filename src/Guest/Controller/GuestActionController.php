<?php

declare(strict_types=1);

namespace App\Guest\Controller;

use App\Guest\Entity\Guest;
use App\Guestbook\Entity\GuestbookEntry;
use App\Guestbook\Repository\GuestbookEntryRepository;
use App\Media\Entity\Media;
use App\Media\MediaDeleter;
use App\Media\Repository\MediaRepository;
use App\Media\Visibility;
use App\Security\Guest\GuestUser;
use App\Security\Voter\MediaVoter;
use App\Security\Voter\ModuleVoter;
use App\Social\LikeService;
use App\Social\ReportReason;
use App\Social\ReportService;
use App\Wedding\Entity\Wedding;
use App\Wedding\ModuleType;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Aktionen der Gast-App (Formulare, Turbo). Jede Aktion prüft CSRF-Token und Berechtigung.
 */
#[Route('/h/{slug}', requirements: ['slug' => Wedding::SLUG_PATTERN], methods: ['POST'])]
final class GuestActionController extends AbstractController
{
    public const string CSRF_ID = 'guest-action';

    public function __construct(
        private readonly MediaRepository $media,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('/m/{id}/like', name: 'guest_media_like')]
    public function like(Request $request, string $id, LikeService $likes): Response
    {
        $guest = $this->guardedGuest($request);
        $media = $this->visibleMedia($id);
        $liked = $likes->toggle($guest, $media);
        $count = $likes->counts([$media])[$media->getId()->toRfc4122()] ?? 0;

        // Vollbild-Ansicht (JavaScript) erwartet JSON, das Formular ohne JavaScript ein Turbo-Frame.
        if (in_array('application/json', $request->getAcceptableContentTypes(), true)) {
            return new JsonResponse(['liked' => $liked, 'count' => $count], Response::HTTP_OK, [
                'Cache-Control' => 'private, no-store',
            ]);
        }

        return $this->render('guest/app/_like.html.twig', [
            'media' => $media,
            'wedding' => $guest->getWedding(),
            'count' => $count,
            'liked' => $liked,
        ]);
    }

    #[Route('/m/{id}/melden', name: 'guest_media_report')]
    public function report(Request $request, string $id, ReportService $reports): Response
    {
        $guest = $this->guardedGuest($request);
        $media = $this->visibleMedia($id);
        $reason = ReportReason::tryFrom($request->request->getString('reason')) ?? ReportReason::Other;

        if (!($media->getUploaderGuest()?->getId()->equals($guest->getId()) ?? false)) {
            $reports->report($guest, $media, $reason, $request->request->getString('note'));
        }

        $this->addFlash('success', 'Danke! Das Brautpaar schaut sich das an.');

        return $this->redirectToRoute('guest_media', ['slug' => $guest->getWedding()->getSlug(), 'id' => $id]);
    }

    #[Route('/m/{id}/loeschen', name: 'guest_media_delete')]
    public function delete(Request $request, string $id, MediaDeleter $deleter): Response
    {
        $guest = $this->guardedGuest($request);
        $media = $this->visibleMedia($id);
        $this->denyAccessUnlessGranted(MediaVoter::DELETE, $media);
        $deleter->delete($media);
        $this->addFlash('success', 'Gelöscht.');

        return $this->redirectToRoute('guest_album', ['slug' => $guest->getWedding()->getSlug()]);
    }

    #[Route('/m/{id}/beschriftung', name: 'guest_media_caption')]
    public function caption(Request $request, string $id): Response
    {
        $guest = $this->guardedGuest($request);
        $media = $this->visibleMedia($id);
        if (!($media->getUploaderGuest()?->getId()->equals($guest->getId()) ?? false)) {
            throw new AccessDeniedHttpException();
        }

        $media->changeCaption($request->request->getString('caption'));
        $this->em->flush();

        return $this->redirectToRoute('guest_media', ['slug' => $guest->getWedding()->getSlug(), 'id' => $id]);
    }

    #[Route('/gaestebuch', name: 'guest_guestbook_write')]
    public function writeGuestbook(Request $request): Response
    {
        $guest = $this->guardedGuest($request);
        $wedding = $guest->getWedding();
        if (!$this->isGranted(ModuleVoter::CONTRIBUTE, $wedding->module(ModuleType::Guestbook))) {
            throw new AccessDeniedHttpException();
        }

        $visibility = Visibility::Couple->value === $request->request->getString('visibility')
            ? Visibility::Couple
            : Visibility::AllGuests;

        try {
            $entry = new GuestbookEntry($guest, $request->request->getString('message'), $visibility);
        } catch (InvalidArgumentException) {
            $this->addFlash('error', sprintf(
                'Bitte schreibe eine Nachricht mit höchstens %d Zeichen.',
                GuestbookEntry::MAX_LENGTH,
            ));

            return $this->redirectToRoute('guest_guestbook', ['slug' => $wedding->getSlug()]);
        }

        $this->em->persist($entry);
        $this->em->flush();
        $this->addFlash(
            'success',
            $entry->getPublicationStatus()->value === 'pending'
                ? 'Danke! Dein Eintrag erscheint nach der Freigabe.'
                : 'Danke für deinen Eintrag!',
        );

        return $this->redirectToRoute('guest_guestbook', ['slug' => $wedding->getSlug()]);
    }

    #[Route('/gaestebuch/{id}/loeschen', name: 'guest_guestbook_delete')]
    public function deleteGuestbook(Request $request, string $id, GuestbookEntryRepository $entries): Response
    {
        $guest = $this->guardedGuest($request);
        $entry = Uuid::isValid($id) ? $entries->find(Uuid::fromString($id)) : null;
        if (null === $entry || !$this->isGranted(MediaVoter::VIEW, $entry)) {
            throw new NotFoundHttpException();
        }

        $this->denyAccessUnlessGranted(MediaVoter::DELETE, $entry);
        $entry->markDeleted(DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->em->flush();

        return $this->redirectToRoute('guest_guestbook', ['slug' => $guest->getWedding()->getSlug()]);
    }

    private function guardedGuest(Request $request): Guest
    {
        $user = $this->getUser();
        if (
            !$user instanceof GuestUser
            || !$this->isCsrfTokenValid(self::CSRF_ID, $request->request->getString('_token'))
        ) {
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
}
