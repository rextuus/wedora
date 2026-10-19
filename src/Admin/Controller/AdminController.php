<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Account\Entity\User;
use App\Account\WeddingPermission;
use App\Security\Voter\WeddingVoter;
use App\Wedding\Entity\Wedding;
use App\Wedding\Repository\WeddingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Basis der Verwaltung: Hochzeit laden, Rechte prüfen, CSRF für alle Aktionen.
 */
abstract class AdminController extends AbstractController
{
    public const string CSRF_ID = 'admin-action';

    private WeddingRepository $weddings;

    #[Required]
    public function setWeddingRepository(WeddingRepository $weddings): void
    {
        $this->weddings = $weddings;
    }

    protected function account(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        return $user;
    }

    /**
     * Unbekannt und nicht berechtigt sehen gleich aus (404), damit sich Hochzeiten nicht erraten lassen.
     */
    protected function wedding(string $slug, ?WeddingPermission $permission = null): Wedding
    {
        $wedding = $this->weddings->findOneBySlug($slug);
        if (null === $wedding || !$this->isGranted(WeddingVoter::VIEW, $wedding)) {
            throw new NotFoundHttpException();
        }

        if (null !== $permission) {
            $this->denyAccessUnlessGranted(WeddingVoter::attribute($permission), $wedding);
        }

        return $wedding;
    }

    protected function requireCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Ungültiges Formular. Bitte Seite neu laden.');
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    protected function page(
        string $template,
        ?Wedding $wedding,
        string $active,
        array $parameters = [],
        ?Response $response = null,
    ): Response {
        $response = $this->render(
            $template,
            [
                'wedding' => $wedding,
                'active' => $active,
                'user' => $this->account(),
                ...$parameters,
            ],
            $response,
        );
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * @return array<string, bool>
     */
    protected function permissions(Wedding $wedding): array
    {
        $granted = [];
        foreach (WeddingPermission::cases() as $permission) {
            $granted[$permission->value] = $this->isGranted(WeddingVoter::attribute($permission), $wedding);
        }

        return $granted;
    }
}
