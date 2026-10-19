<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Account\WeddingPermission;
use App\Ai\ImageGenerator;
use App\Wedding\Entity\Wedding;
use App\Wedding\ModerationMode;
use App\Wedding\ModuleType;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/admin/h/{slug}/einstellungen', requirements: ['slug' => Wedding::SLUG_PATTERN])]
final class SettingsController extends AdminController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(int:VIDEO_MAX_DURATION_SECONDS)%')]
        private readonly int $platformMaxVideoSeconds,
        private readonly ImageGenerator $images,
    ) {}

    #[Route('', name: 'admin_settings', methods: ['GET'])]
    public function index(string $slug): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::Configure);

        return $this->page('admin/settings.html.twig', $wedding, 'settings', [
            'modes' => ModerationMode::cases(),
            'modules' => ModuleType::cases(),
            'permissions' => $this->permissions($wedding),
            'platformMaxVideoSeconds' => $this->platformMaxVideoSeconds,
            'portraitsAvailable' => $this->images->isAvailable(),
        ]);
    }

    #[Route('/allgemein', name: 'admin_settings_general', methods: ['POST'])]
    public function general(Request $request, string $slug): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::Configure);
        $this->requireCsrf($request);

        $title = trim($request->request->getString('title'));
        $date = $request->request->getString('date');
        if ('' === $title || mb_strlen($title) > 120) {
            $this->addFlash('error', 'Bitte einen Namen mit höchstens 120 Zeichen angeben.');

            return $this->redirectToRoute('admin_settings', ['slug' => $slug]);
        }

        $wedding->rename($title);
        $wedding->setWeddingDate(
            1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? new DateTimeImmutable($date) : null,
        );
        $wedding->setModerationMode(
            ModerationMode::tryFrom($request->request->getString('moderation')) ?? $wedding->getModerationMode(),
        );
        $this->em->flush();
        $this->addFlash('success', 'Gespeichert.');

        return $this->redirectToRoute('admin_settings', ['slug' => $slug]);
    }

    #[Route('/module', name: 'admin_settings_modules', methods: ['POST'])]
    public function modules(Request $request, string $slug): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::Configure);
        $this->requireCsrf($request);

        foreach (ModuleType::cases() as $type) {
            $module = $wedding->module($type);
            $module->setEnabled($request->request->has('enabled_' . $type->value));
            $close = $request->request->getString('close_' . $type->value);
            $module->closeContributionsAt('' === $close ? null : new DateTimeImmutable($close));
        }

        $wedding->enableChallengePoints($request->request->has('points'));
        $wedding->enablePortraits($request->request->has('portraits'));
        $this->em->flush();
        $this->addFlash('success', 'Module gespeichert.');

        return $this->redirectToRoute('admin_settings', ['slug' => $slug]);
    }

    #[Route('/lebenszyklus', name: 'admin_settings_lifecycle', methods: ['POST'])]
    public function lifecycle(Request $request, string $slug): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::ManageLifecycle);
        $this->requireCsrf($request);

        if ('archivieren' === $request->request->getString('action')) {
            $wedding->archive();
            $this->addFlash(
                'success',
                'Archiviert: Gäste können weiter ansehen, aber nichts mehr beitragen. Es wurde nichts gelöscht.',
            );
        } else {
            $wedding->reactivate();
            $this->addFlash('success', 'Wieder aktiv.');
        }

        $this->em->flush();

        return $this->redirectToRoute('admin_settings', ['slug' => $slug]);
    }

    /**
     * Limits legt nur die Plattformadministration fest (innerhalb der Plattform-Obergrenzen).
     */
    #[Route('/limits', name: 'admin_settings_limits', methods: ['POST'])]
    #[IsGranted('ROLE_PLATFORM_ADMIN')]
    public function limits(Request $request, string $slug): Response
    {
        $wedding = $this->wedding($slug);
        $this->requireCsrf($request);
        $mb = static fn(string $field): int => self::bytes($request->request->getString($field), 1024 ** 2) ?? 0;
        $guestQuota = trim($request->request->getString('guest_quota_gb'));

        try {
            $wedding->limits()->change(
                min($this->platformMaxVideoSeconds, $request->request->getInt('max_video_seconds')),
                $mb('max_video_mb'),
                $mb('max_photo_mb'),
                self::bytes($request->request->getString('storage_quota_gb'), 1024 ** 3) ?? 0,
                '' === $guestQuota ? null : self::bytes($guestQuota, 1024 ** 3) ?? 0,
            );
        } catch (InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_settings', ['slug' => $slug]);
        }

        $this->em->flush();
        $this->addFlash('success', 'Limits gespeichert.');

        return $this->redirectToRoute('admin_settings', ['slug' => $slug]);
    }

    /**
     * Zahl aus dem Formular (Komma oder Punkt) in Bytes; null bei ungültiger Eingabe.
     */
    private static function bytes(string $value, int $unit): ?int
    {
        $value = str_replace(',', '.', trim($value));

        return is_numeric($value) ? (int) round((float) $value * $unit) : null;
    }
}
