<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Account\WeddingPermission;
use App\Ai\AiBudget;
use App\Ai\ImageGenerator;
use App\Guest\Portrait\PortraitQuestions;
use App\Guest\Portrait\PortraitStyle;
use App\Guest\Portrait\PortraitStyleMode;
use App\Media\MediaType;
use App\Media\Processing\EnhanceWeddingPhotos;
use App\Media\Repository\MediaRepository;
use App\Wedding\CoupleNames;
use App\Wedding\CoverMode;
use App\Wedding\Entity\Wedding;
use App\Wedding\Host\HostPronoun;
use App\Wedding\Host\WeddingPicture;
use App\Wedding\Host\WeddingPictureStorage;
use App\Wedding\Host\WeddingPictureUpload;
use App\Wedding\ModerationMode;
use App\Wedding\ModuleType;
use App\Wedding\NudgeMode;
use App\Wedding\PhotoLook;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
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
        private readonly AiBudget $aiBudget,
    ) {}

    #[Route('', name: 'admin_settings', methods: ['GET'])]
    public function index(string $slug, MediaRepository $media): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::Configure);

        return $this->page('admin/settings.html.twig', $wedding, 'settings', [
            'modes' => ModerationMode::cases(),
            'modules' => ModuleType::cases(),
            'permissions' => $this->permissions($wedding),
            'platformMaxVideoSeconds' => $this->platformMaxVideoSeconds,
            'portraitsAvailable' => $this->images->isAvailable(),
            'aiUsage' => $this->aiBudget->usage(),
            'portraitStyles' => PortraitStyle::cases(),
            'portraitStyleModes' => PortraitStyleMode::cases(),
            'coverModes' => CoverMode::cases(),
            'pronouns' => HostPronoun::cases(),
            'nudgeModes' => NudgeMode::cases(),
            'photoLooks' => PhotoLook::cases(),
            'portraitQuestions' => PortraitQuestions::all(CoupleNames::of($wedding)),
            // Vorschau der Looks am neuesten Foto der Hochzeit.
            'lookSample' => $media->findOneBy([
                'wedding' => $wedding,
                'type' => MediaType::Photo,
                'deletedAt' => null,
            ], ['id' => 'DESC']),
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
        $wedding->enablePortraitGadgets($request->request->has('portrait_gadgets'));
        $wedding->decidePortraitStylesBy(
            PortraitStyleMode::tryFrom($request->request->getString(
                'portrait_style_mode',
            )) ?? $wedding->getPortraitStyleMode(),
        );
        $wedding->offerPortraitStyles(array_values(array_filter(array_map(
            static fn(mixed $value): ?PortraitStyle => is_string($value) ? PortraitStyle::tryFrom($value) : null,
            $request->request->all('portrait_styles'),
        ))));
        $this->em->flush();
        $this->addFlash('success', 'Module gespeichert.');

        return $this->redirectToRoute('admin_settings', ['slug' => $slug]);
    }

    /** Vertrauenshinweis „Wo liegen meine Fotos?“: wer den Server betreibt, was für ein Server, je optional mit Foto. */
    #[Route('/gastgeber', name: 'admin_settings_host', methods: ['POST'])]
    public function host(Request $request, string $slug, WeddingPictureUpload $pictures): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::Configure);
        $this->requireCsrf($request);
        $presentation = $wedding->getPresentation();

        try {
            $presentation->describeHost(
                $request->request->getString('host_name'),
                $request->request->getString('host_note'),
                HostPronoun::tryFrom($request->request->getString('host_pronoun')),
            );
            $presentation->describeServer($request->request->getString('server_note'));
            self::applyPicture($request, 'host_photo', $wedding, WeddingPicture::Host, $pictures);
            self::applyPicture($request, 'server_photo', $wedding, WeddingPicture::Server, $pictures);
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_settings', ['slug' => $slug]);
        }

        $this->em->flush();
        $this->addFlash('success', 'Hinweis gespeichert.');

        return $this->redirectToRoute('admin_settings', ['slug' => $slug]);
    }

    /** Bild oben auf der Startseite der Gäste: neuestes Foto, festes Titelbild oder keins. */
    #[Route('/titelbild', name: 'admin_settings_cover', methods: ['POST'])]
    public function cover(Request $request, string $slug, WeddingPictureUpload $pictures): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::Configure);
        $this->requireCsrf($request);
        $presentation = $wedding->getPresentation();

        try {
            self::applyPicture($request, 'cover_photo', $wedding, WeddingPicture::Cover, $pictures);
            $mode = CoverMode::tryFrom($request->request->getString('cover_mode')) ?? CoverMode::Latest;
            if (CoverMode::Fixed === $mode && null === $presentation->getCoverPhotoAt()) {
                throw new InvalidArgumentException('Für ein festes Titelbild bitte ein Foto hochladen.');
            }

            $presentation->chooseCover($mode);
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_settings', ['slug' => $slug]);
        }

        $this->em->flush();
        $this->addFlash('success', 'Titelbild gespeichert.');

        return $this->redirectToRoute('admin_settings', ['slug' => $slug]);
    }

    /** Hinweis „Zu viel am Handy?“: nur auf Antippen oder auch von selbst (nach Zeit bzw. vielen Beiträgen). */
    #[Route('/handy-hinweis', name: 'admin_settings_nudge', methods: ['POST'])]
    public function nudge(Request $request, string $slug): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::Configure);
        $this->requireCsrf($request);

        try {
            $wedding->getPresentation()->configureNudge(
                NudgeMode::tryFrom($request->request->getString('nudge_mode')) ?? NudgeMode::Time,
                $request->request->getInt('nudge_threshold'),
            );
        } catch (InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_settings', ['slug' => $slug]);
        }

        $this->em->flush();
        $this->addFlash('success', 'Handy-Hinweis gespeichert.');

        return $this->redirectToRoute('admin_settings', ['slug' => $slug]);
    }

    /** Gemeinsamer Look aller Fotos (nur Anzeige). */
    #[Route('/foto-look', name: 'admin_settings_look', methods: ['POST'])]
    public function look(Request $request, string $slug, MessageBusInterface $bus): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::Configure);
        $this->requireCsrf($request);
        $presentation = $wedding->getPresentation();
        $presentation->choosePhotoLook(
            PhotoLook::tryFrom($request->request->getString('photo_look')) ?? PhotoLook::Natural,
        );
        $enhance = $request->request->has('auto_enhance');
        $changed = $enhance !== $presentation->enhancesPhotos();
        $presentation->enhancePhotos($enhance);
        $this->em->flush();
        // Vorhandene Fotos im Hintergrund nachziehen (an: angleichen, aus: Fassungen entfernen).
        if ($changed) {
            $bus->dispatch(new EnhanceWeddingPhotos($wedding->getId()->toRfc4122()));
        }
        $this->addFlash('success', 'Foto-Look gespeichert.');

        return $this->redirectToRoute('admin_settings', ['slug' => $slug]);
    }

    /** Vorschau der hochgeladenen Bilder in der Verwaltung (die Gäste-Adressen gelten nur für Gäste). */
    #[Route(
        '/bild/{kind}.jpg',
        name: 'admin_wedding_picture',
        requirements: ['kind' => 'server|titelbild'],
        methods: ['GET'],
    )]
    public function preview(string $slug, string $kind, WeddingPictureStorage $storage): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::Configure);
        $picture = 'server' === $kind ? WeddingPicture::Server : WeddingPicture::Cover;
        $path = $storage->path($wedding, $picture);
        if (null === $wedding->getPresentation()->pictureAt($picture) || !is_file($path)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path, headers: ['Content-Type' => 'image/jpeg']);
        $response->setPrivate();

        return $response;
    }

    /**
     * Neues Bild aus dem Formularfeld übernehmen bzw. auf Wunsch („<feld>_entfernen“) entfernen.
     *
     * @throws InvalidArgumentException|RuntimeException
     */
    private static function applyPicture(
        Request $request,
        string $field,
        Wedding $wedding,
        WeddingPicture $picture,
        WeddingPictureUpload $pictures,
    ): void {
        // @mago-expect analysis:mixed-assignment
        $file = $request->files->get($field);
        if ($request->request->has($field . '_entfernen')) {
            $pictures->remove($wedding, $picture);
        } elseif ($file instanceof UploadedFile) {
            $pictures->replace($wedding, $picture, $file);
        }
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
