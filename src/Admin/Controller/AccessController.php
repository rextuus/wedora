<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Account\WeddingPermission;
use App\Guest\Repository\GuestRepository;
use App\Wedding\Entity\Invitation;
use App\Wedding\Entity\Wedding;
use App\Wedding\PublicLinks;
use App\Wedding\Repository\InvitationRepository;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('ROLE_USER')]
#[Route('/admin/h/{slug}/zugang', requirements: ['slug' => Wedding::SLUG_PATTERN])]
final class AccessController extends AdminController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvitationRepository $invitations,
        private readonly PublicLinks $links,
    ) {}

    #[Route('', name: 'admin_access', methods: ['GET'])]
    public function index(string $slug, GuestRepository $guests, Request $request): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::ManageAccess);

        return $this->page('admin/access.html.twig', $wedding, 'access', [
            'joinUrl' => $this->joinUrl($wedding),
            'invitations' => $this->invitations->findBy(['wedding' => $wedding], ['createdAt' => 'DESC']),
            'guests' => $guests->findBy(['wedding' => $wedding], ['createdAt' => 'DESC']),
            'newInvitationUrl' => self::takeInvitationUrl($request),
        ]);
    }

    /**
     * QR-Code des Beitrittslinks als SVG (zum Ausdrucken).
     */
    #[Route('/qr.svg', name: 'admin_access_qr', methods: ['GET'])]
    public function qr(string $slug): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::ManageAccess);
        $options = [
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => false,
            'svgAddXmlHeader' => true,
            'drawLightModules' => false,
        ];

        // @mago-expect analysis:mixed-assignment
        $svg = new QRCode($options)->render($this->joinUrl($wedding));

        return new Response(is_string($svg) ? $svg : '', Response::HTTP_OK, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => sprintf('inline; filename="%s-qr.svg"', $wedding->getSlug()),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    #[Route('/link', name: 'admin_access_link', methods: ['POST'])]
    public function link(Request $request, string $slug): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::ManageAccess);
        $this->requireCsrf($request);

        $access = $wedding->access();
        $action = $request->request->getString('action');
        match ($action) {
            'neu' => $access->rotateOpenLinkToken(),
            'aus' => $access->enableOpenLink(false),
            'an' => $access->enableOpenLink(true),
            'einladungen-an' => $access->enableInvitations(true),
            'einladungen-aus' => $access->enableInvitations(false),
            default => throw new NotFoundHttpException(),
        };
        $this->em->flush();
        $this->addFlash(
            'success',
            'neu' === $action
                ? 'Neuer QR-Code erstellt. Der alte funktioniert nicht mehr für neue Gäste – bereits beigetretene bleiben drin.'
                : 'Gespeichert.',
        );

        return $this->redirectToRoute('admin_access', ['slug' => $slug]);
    }

    #[Route('/passwort', name: 'admin_access_password', methods: ['POST'])]
    public function password(Request $request, string $slug, PasswordHasherFactoryInterface $hashers): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::ManageAccess);
        $this->requireCsrf($request);
        $password = $request->request->getString('password');

        if ('entfernen' === $request->request->getString('action')) {
            $wedding->access()->setPasswordHash(null);
            $this->addFlash('success', 'Passwort entfernt – der QR-Code genügt jetzt zum Beitreten.');
        } elseif (mb_strlen($password) < 6) {
            $this->addFlash('error', 'Das Passwort muss mindestens 6 Zeichen haben.');
        } else {
            $wedding->access()->setPasswordHash($hashers->getPasswordHasher('wedding_password')->hash($password));
            $this->addFlash(
                'success',
                'Passwort gesetzt. Neue Gäste brauchen es zusätzlich zum QR-Code; Einladungslinks funktionieren ohne.',
            );
        }

        $this->em->flush();

        return $this->redirectToRoute('admin_access', ['slug' => $slug]);
    }

    #[Route('/einladungen', name: 'admin_invitation_create', methods: ['POST'])]
    public function invite(Request $request, string $slug): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::ManageAccess);
        $this->requireCsrf($request);
        $label = trim($request->request->getString('label'));
        if ('' === $label || mb_strlen($label) > 120) {
            $this->addFlash('error', 'Bitte eine Bezeichnung angeben, z. B. „Familie Müller“.');

            return $this->redirectToRoute('admin_access', ['slug' => $slug]);
        }

        $expires = $request->request->getString('expires');
        [$invitation, $token] = Invitation::create(
            $wedding,
            $label,
            '' === $expires ? null : new DateTimeImmutable($expires . ' 23:59:59'),
        );
        $this->em->persist($invitation);
        $this->em->flush();

        // Der Link existiert nur jetzt im Klartext und wird genau einmal angezeigt.
        $this->addFlash('invitation_url', $this->links->invitation($token));

        return $this->redirectToRoute('admin_access', ['slug' => $slug]);
    }

    #[Route('/einladungen/{id}/widerrufen', name: 'admin_invitation_revoke', methods: ['POST'])]
    public function revoke(Request $request, string $slug, string $id): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::ManageAccess);
        $this->requireCsrf($request);
        $invitation = Uuid::isValid($id) ? $this->invitations->find(Uuid::fromString($id)) : null;
        if (null === $invitation || $invitation->getWedding() !== $wedding) {
            throw new NotFoundHttpException();
        }

        $invitation->revoke(new DateTimeImmutable());
        $this->em->flush();
        $this->addFlash('success', sprintf(
            'Einladung „%s“ widerrufen. Gäste, die darüber beigetreten sind, haben keinen Zugang mehr.',
            $invitation->getLabel(),
        ));

        return $this->redirectToRoute('admin_access', ['slug' => $slug]);
    }

    #[Route(
        '/gaeste/{id}/{action}',
        name: 'admin_guest_block',
        requirements: ['action' => 'sperren|entsperren'],
        methods: ['POST'],
    )]
    public function block(Request $request, string $slug, string $id, string $action, GuestRepository $guests): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::ManageAccess);
        $this->requireCsrf($request);
        $guest = Uuid::isValid($id) ? $guests->find(Uuid::fromString($id)) : null;
        if (null === $guest || $guest->getWedding() !== $wedding) {
            throw new NotFoundHttpException();
        }

        'sperren' === $action ? $guest->block(new DateTimeImmutable()) : $guest->unblock();
        $this->em->flush();
        $this->addFlash('success', sprintf(
            '%s wurde %s.',
            $guest->getDisplayName(),
            'sperren' === $action ? 'gesperrt' : 'entsperrt',
        ));

        return $this->redirectToRoute('admin_access', ['slug' => $slug]);
    }

    /**
     * Der Klartext-Link einer neuen Einladung wird genau einmal (per Flash) angezeigt.
     */
    private static function takeInvitationUrl(Request $request): ?string
    {
        $session = $request->getSession();
        $urls = $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag()->get('invitation_url') : [];

        return array_key_exists(0, $urls) && is_string($urls[0]) ? $urls[0] : null;
    }

    private function joinUrl(Wedding $wedding): string
    {
        return $this->links->join($wedding);
    }
}
