<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Account\WeddingPermission;
use App\Challenge\ChallengeMediaType;
use App\Challenge\ChallengeProgress;
use App\Challenge\ChallengeTemplates;
use App\Challenge\Entity\Challenge;
use App\Challenge\Repository\ChallengeRepository;
use App\Wedding\Entity\Wedding;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('ROLE_USER')]
#[Route('/admin/h/{slug}/aufgaben', requirements: ['slug' => Wedding::SLUG_PATTERN])]
final class ChallengeAdminController extends AdminController
{
    public function __construct(
        private readonly ChallengeRepository $challenges,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'admin_challenges', methods: ['GET'])]
    public function index(string $slug, ChallengeProgress $progress): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::Configure);
        $list = $this->challenges->forWedding($wedding);
        $existing = array_map(static fn(Challenge $c): string => $c->getTitle(), $list);

        return $this->page('admin/challenges.html.twig', $wedding, 'challenges', [
            'challenges' => $list,
            'participants' => $progress->participants($list),
            'templates' => array_filter(
                ChallengeTemplates::all(),
                static fn(array $t): bool => !in_array($t['title'], $existing, true),
            ),
            'types' => ChallengeMediaType::cases(),
        ]);
    }

    #[Route('/neu', name: 'admin_challenge_create', methods: ['POST'])]
    public function create(Request $request, string $slug): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::Configure);
        $this->requireCsrf($request);
        $template = ChallengeTemplates::all()[$request->request->getString('template')] ?? null;

        try {
            $challenge = new Challenge($wedding, $template['title'] ?? $request->request->getString('title'));
            if (null !== $template) {
                $challenge->describe($template['description']);
                $challenge->setMediaType($template['type']);
                $challenge->setPoints($template['points']);
            } else {
                $this->apply($challenge, $request);
            }
        } catch (InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_challenges', ['slug' => $slug]);
        }

        if ($this->hasOtherPoll($challenge)) {
            $this->addFlash('error', 'Es gibt schon eine Umfrage – die Fragen gehören zur ganzen Hochzeit.');

            return $this->redirectToRoute('admin_challenges', ['slug' => $slug]);
        }

        $challenge->moveTo(count($this->challenges->forWedding($wedding)));
        $this->em->persist($challenge);
        $this->em->flush();
        $this->addFlash('success', sprintf('Aufgabe „%s“ angelegt.', $challenge->getTitle()));

        return $this->redirectToRoute('admin_challenges', ['slug' => $slug]);
    }

    #[Route('/{id}', name: 'admin_challenge_update', methods: ['POST'])]
    public function update(Request $request, string $slug, string $id): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::Configure);
        $this->requireCsrf($request);
        $challenge = $this->find($wedding, $id);

        if ('loeschen' === $request->request->getString('action')) {
            // Beiträge bleiben erhalten; sie verlieren nur die Zuordnung zur Aufgabe.
            $this->em->remove($challenge);
            $this->em->flush();
            $this->addFlash('success', 'Aufgabe gelöscht. Die Beiträge dazu bleiben in der Galerie.');

            return $this->redirectToRoute('admin_challenges', ['slug' => $slug]);
        }

        try {
            $challenge->rename($request->request->getString('title'));
            $this->apply($challenge, $request);
            if ($this->hasOtherPoll($challenge)) {
                throw new InvalidArgumentException(
                    'Es gibt schon eine Umfrage – die Fragen gehören zur ganzen Hochzeit.',
                );
            }
        } catch (InvalidArgumentException $e) {
            $this->em->refresh($challenge);
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_challenges', ['slug' => $slug]);
        }

        $this->em->flush();
        $this->addFlash('success', 'Gespeichert.');

        return $this->redirectToRoute('admin_challenges', ['slug' => $slug]);
    }

    private function apply(Challenge $challenge, Request $request): void
    {
        $challenge->describe($request->request->getString('description'));
        $challenge->setMediaType(
            ChallengeMediaType::tryFrom($request->request->getString('type')) ?? ChallengeMediaType::Photo,
        );
        $challenge->setPoints($request->request->getInt('points'));
        $challenge->setEnabled($request->request->has('enabled'));
        $starts = $request->request->getString('starts_at');
        $ends = $request->request->getString('ends_at');
        $challenge->schedule(
            '' === $starts ? null : new DateTimeImmutable($starts),
            '' === $ends ? null : new DateTimeImmutable($ends),
        );
    }

    /** Eine Umfrage pro Hochzeit: Die Fragen hängen an der Hochzeit, nicht an der Aufgabe. */
    private function hasOtherPoll(Challenge $challenge): bool
    {
        if (!$challenge->getMediaType()->isPoll()) {
            return false;
        }

        foreach ($this->challenges->forWedding($challenge->getWedding()) as $other) {
            if ($other !== $challenge && $other->getMediaType()->isPoll()) {
                return true;
            }
        }

        return false;
    }

    private function find(Wedding $wedding, string $id): Challenge
    {
        $challenge = Uuid::isValid($id) ? $this->challenges->find(Uuid::fromString($id)) : null;
        if (null === $challenge || $challenge->getWedding() !== $wedding) {
            throw new NotFoundHttpException();
        }

        return $challenge;
    }
}
