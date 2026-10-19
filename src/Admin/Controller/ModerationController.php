<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Account\WeddingPermission;
use App\Guestbook\Entity\GuestbookEntry;
use App\Guestbook\Repository\GuestbookEntryRepository;
use App\Media\Entity\Media;
use App\Media\MediaDeleter;
use App\Media\Repository\MediaRepository;
use App\Moderation\ModerationQueue;
use App\Security\Voter\MediaVoter;
use App\Social\Repository\MediaReportRepository;
use App\Wedding\Entity\Wedding;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('ROLE_USER')]
#[Route('/admin/h/{slug}/moderation', requirements: ['slug' => Wedding::SLUG_PATTERN])]
final class ModerationController extends AdminController
{
    public function __construct(
        private readonly MediaRepository $media,
        private readonly GuestbookEntryRepository $entries,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly MediaReportRepository $reports,
        private readonly MediaDeleter $deleter,
    ) {}

    #[Route('', name: 'admin_moderation', methods: ['GET'])]
    public function index(string $slug, ModerationQueue $queue): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::Moderate);

        return $this->page('admin/moderation.html.twig', $wedding, 'moderation', [
            'pending' => $queue->pending($wedding),
            'unreviewed' => $queue->unreviewed($wedding),
            'reports' => $queue->openReports($wedding),
        ]);
    }

    /**
     * Aktion auf einen Beitrag: freigeben, ablehnen, gesichtet, löschen.
     */
    #[Route(
        '/{kind}/{id}/{action}',
        name: 'admin_moderation_action',
        requirements: [
            'kind' => 'media|eintrag',
            'action' => 'freigeben|ablehnen|gesichtet|loeschen',
        ],
        methods: ['POST'],
    )]
    public function act(Request $request, string $slug, string $kind, string $id, string $action): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::Moderate);
        $this->requireCsrf($request);
        $item = $this->item($wedding, $kind, $id);
        $this->denyAccessUnlessGranted(MediaVoter::MODERATE, $item);
        $now = $this->now();

        match ($action) {
            'freigeben' => $item->approve($now),
            'ablehnen' => $item->reject($now),
            'gesichtet' => $item->markReviewed($now),
            default => $item instanceof Media ? $this->deleter->delete($item) : $item->markDeleted($now),
        };

        if ($item instanceof Media && in_array($action, ['ablehnen', 'loeschen'], true)) {
            foreach ($this->reports->findBy(['media' => $item]) as $report) {
                $report->resolve($this->account(), $now);
            }
        }

        $this->em->flush();

        $this->addFlash('success', match ($action) {
            'freigeben' => 'Freigegeben.',
            'ablehnen' => 'Abgelehnt – nur noch für den Gast selbst sichtbar.',
            'gesichtet' => 'Als gesichtet markiert.',
            default => 'Gelöscht.',
        });

        return $this->redirectToRoute('admin_moderation', ['slug' => $slug]);
    }

    #[Route('/meldung/{id}/erledigt', name: 'admin_report_resolve', methods: ['POST'])]
    public function resolveReport(Request $request, string $slug, string $id): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::Moderate);
        $this->requireCsrf($request);
        $report = Uuid::isValid($id) ? $this->reports->find(Uuid::fromString($id)) : null;
        if (null === $report || $report->getMedia()->getWedding() !== $wedding) {
            throw new NotFoundHttpException();
        }

        // Alle offenen Meldungen zu diesem Beitrag gelten als erledigt.
        foreach ($this->reports->findBy(['media' => $report->getMedia()]) as $other) {
            $other->resolve($this->account(), $this->now());
        }
        $this->em->flush();
        $this->addFlash('success', 'Meldung erledigt – der Beitrag bleibt sichtbar.');

        return $this->redirectToRoute('admin_moderation', ['slug' => $slug]);
    }

    private function item(Wedding $wedding, string $kind, string $id): Media|GuestbookEntry
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException();
        }

        $uuid = Uuid::fromString($id);
        $item = 'media' === $kind ? $this->media->find($uuid) : $this->entries->find($uuid);

        if (null === $item || $item->getWedding() !== $wedding || $item->isDeleted()) {
            throw new NotFoundHttpException();
        }

        return $item;
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
