<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Account\Repository\WeddingMemberRepository;
use App\Ai\AiBudget;
use App\Guest\Repository\GuestRepository;
use App\Media\MediaType;
use App\Media\Repository\MediaRepository;
use App\Media\Upload\StorageUsage;
use App\Moderation\ModerationQueue;
use App\Wedding\Entity\Wedding;
use App\Wedding\Repository\WeddingRepository;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class DashboardController extends AdminController
{
    public function __construct(
        private readonly GuestRepository $guests,
        private readonly MediaRepository $media,
        private readonly StorageUsage $usage,
        private readonly ModerationQueue $queue,
    ) {}

    #[Route('/admin', name: 'app_admin', methods: ['GET'])]
    public function index(WeddingMemberRepository $members, WeddingRepository $weddings, AiBudget $aiBudget): Response
    {
        $user = $this->account();
        $list = $user->isPlatformAdmin()
            ? $weddings->findBy([], ['createdAt' => 'DESC'])
            : array_map(static fn($m) => $m->getWedding(), $members->findBy(['user' => $user]));

        return $this->page('admin/index.html.twig', null, 'index', [
            'weddings' => $list,
            'aiUsage' => $user->isPlatformAdmin() ? $aiBudget->usage() : null,
        ]);
    }

    #[Route(
        '/admin/h/{slug}',
        name: 'admin_wedding',
        requirements: ['slug' => Wedding::SLUG_PATTERN],
        methods: ['GET'],
    )]
    public function overview(string $slug): Response
    {
        $wedding = $this->wedding($slug);
        $used = $this->usage->forWedding($wedding, new DateTimeImmutable());
        $count = fn(MediaType $type): int => $this->media->count([
            'wedding' => $wedding,
            'type' => $type,
            'deletedAt' => null,
        ]);

        return $this->page('admin/overview.html.twig', $wedding, 'overview', [
            'stats' => [
                'guests' => $this->guests->count(['wedding' => $wedding]),
                'photos' => $count(MediaType::Photo),
                'videos' => $count(MediaType::Video),
            ],
            'usedBytes' => $used,
            'usagePercent' => min(100, (int) round((100 * $used) / max(1, $wedding->limits()->storageQuotaBytes()))),
            'queue' => $this->isGranted('WEDDING_MODERATE', $wedding) ? $this->queue->counts($wedding) : null,
            'permissions' => $this->permissions($wedding),
        ]);
    }
}
