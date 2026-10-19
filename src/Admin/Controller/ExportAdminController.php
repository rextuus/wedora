<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Account\WeddingPermission;
use App\Media\Export\ExportService;
use App\Media\Repository\ExportRepository;
use App\Wedding\Entity\Wedding;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/admin/h/{slug}/export', requirements: ['slug' => Wedding::SLUG_PATTERN])]
final class ExportAdminController extends AdminController
{
    #[Route('', name: 'admin_export', methods: ['GET'])]
    public function index(string $slug, ExportRepository $exports): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::Export);

        return $this->page('admin/export.html.twig', $wedding, 'export', [
            'exports' => $exports->findBy(['wedding' => $wedding], ['createdAt' => 'DESC'], 20),
        ]);
    }

    #[Route('', name: 'admin_export_request', methods: ['POST'])]
    public function request(Request $request, string $slug, ExportService $exports): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::Export);
        $this->requireCsrf($request);
        $exports->request($wedding, $this->account());
        $this->addFlash(
            'success',
            'Export angefordert. Er wird im Hintergrund erstellt – lade die Seite in ein paar Minuten neu.',
        );

        return $this->redirectToRoute('admin_export', ['slug' => $slug]);
    }
}
