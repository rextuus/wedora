<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Account\Entity\User;
use App\Account\Entity\WeddingMember;
use App\Account\Repository\UserRepository;
use App\Account\Repository\WeddingMemberRepository;
use App\Account\WeddingPermission;
use App\Wedding\Entity\Wedding;
use App\Wedding\Repository\WeddingRepository;
use App\Wedding\WeddingEraser;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Nur Plattformadministration: Hochzeiten anlegen und löschen, Team und Rechte vergeben.
 */
#[IsGranted('ROLE_PLATFORM_ADMIN')]
final class PlatformController extends AdminController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/admin/neu', name: 'admin_wedding_create', methods: ['GET', 'POST'])]
    public function create(Request $request, WeddingRepository $weddings): Response
    {
        $error = null;
        if ($request->isMethod('POST')) {
            $this->requireCsrf($request);
            $slug = mb_strtolower(trim($request->request->getString('slug')));
            $date = $request->request->getString('date');

            try {
                if (null !== $weddings->findOneBySlug($slug)) {
                    throw new InvalidArgumentException('Dieses Kürzel ist bereits vergeben.');
                }

                $title = trim($request->request->getString('title'));
                $wedding = new Wedding(
                    $slug,
                    '' === $title ? $slug : $title,
                    '' === $date ? null : new DateTimeImmutable($date),
                );
                $this->em->persist($wedding);
                $this->em->flush();
                $this->addFlash('success', 'Hochzeit angelegt. Als Nächstes: Team einladen und den QR-Code drucken.');

                return $this->redirectToRoute('admin_wedding', ['slug' => $slug]);
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->page('admin/wedding_new.html.twig', null, 'index', ['error' => $error]);
    }

    #[Route(
        '/admin/h/{slug}/team',
        name: 'admin_team',
        requirements: ['slug' => Wedding::SLUG_PATTERN],
        methods: ['GET'],
    )]
    public function team(string $slug, WeddingMemberRepository $members): Response
    {
        $wedding = $this->wedding($slug);

        return $this->page('admin/team.html.twig', $wedding, 'team', [
            'members' => $members->findBy(['wedding' => $wedding]),
            'allPermissions' => WeddingPermission::cases(),
        ]);
    }

    #[Route(
        '/admin/h/{slug}/team',
        name: 'admin_team_save',
        requirements: ['slug' => Wedding::SLUG_PATTERN],
        methods: ['POST'],
    )]
    public function saveMember(
        Request $request,
        string $slug,
        UserRepository $users,
        WeddingMemberRepository $members,
        UserPasswordHasherInterface $hasher,
    ): Response {
        $wedding = $this->wedding($slug);
        $this->requireCsrf($request);
        $email = mb_strtolower(trim($request->request->getString('email')));
        $user = $users->findOneByEmail($email);

        if (null === $user) {
            $password = $request->request->getString('password');
            if (false === filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 12) {
                $this->addFlash(
                    'error',
                    'Für ein neues Konto bitte gültige E-Mail und ein Startpasswort mit mindestens 12 Zeichen angeben.',
                );

                return $this->redirectToRoute('admin_team', ['slug' => $slug]);
            }

            $name = trim($request->request->getString('name'));
            $user = new User($email, '' === $name ? $email : $name);
            $user->setPassword($hasher->hashPassword($user, $password));
            $this->em->persist($user);
        }

        $permissions = array_values(array_filter(array_map(static fn(mixed $p): ?WeddingPermission => is_string($p)
            ? WeddingPermission::tryFrom($p)
            : null, $request->request->all('permissions'))));
        $member = $members->findOneFor($user, $wedding);
        if (null === $member) {
            $this->em->persist(
                new WeddingMember($user, $wedding, $permissions, trim($request->request->getString('label'))),
            );
        } else {
            $member->setPermissions($permissions);
        }

        $this->em->flush();
        $this->addFlash('success', sprintf('Rechte für %s gespeichert.', $user->getEmail()));

        return $this->redirectToRoute('admin_team', ['slug' => $slug]);
    }

    #[Route(
        '/admin/h/{slug}/team/{id}/entfernen',
        name: 'admin_team_remove',
        requirements: ['slug' => Wedding::SLUG_PATTERN],
        methods: ['POST'],
    )]
    public function removeMember(Request $request, string $slug, string $id, WeddingMemberRepository $members): Response
    {
        $wedding = $this->wedding($slug);
        $this->requireCsrf($request);
        $member = Uuid::isValid($id) ? $members->find(Uuid::fromString($id)) : null;
        if (null === $member || $member->getWedding() !== $wedding) {
            throw new NotFoundHttpException();
        }

        $this->em->remove($member);
        $this->em->flush();
        $this->addFlash('success', 'Aus dem Team entfernt.');

        return $this->redirectToRoute('admin_team', ['slug' => $slug]);
    }

    #[Route(
        '/admin/h/{slug}/loeschen',
        name: 'admin_wedding_delete',
        requirements: ['slug' => Wedding::SLUG_PATTERN],
        methods: ['GET', 'POST'],
    )]
    public function delete(Request $request, string $slug, WeddingEraser $eraser): Response
    {
        $wedding = $this->wedding($slug);

        if ($request->isMethod('POST')) {
            $this->requireCsrf($request);
            if ($request->request->getString('confirm') !== $wedding->getSlug()) {
                $this->addFlash('error', 'Zur Bestätigung bitte genau das Kürzel eingeben.');

                return $this->redirectToRoute('admin_wedding_delete', ['slug' => $slug]);
            }

            $eraser->erase($wedding);
            $this->addFlash('success', 'Die Hochzeit wurde mit allen Fotos, Videos und Daten endgültig gelöscht.');

            return $this->redirectToRoute('app_admin');
        }

        return $this->page('admin/wedding_delete.html.twig', $wedding, 'settings');
    }
}
