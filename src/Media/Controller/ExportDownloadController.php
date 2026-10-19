<?php

declare(strict_types=1);

namespace App\Media\Controller;

use App\Account\WeddingPermission;
use App\Media\Export\ExportFiles;
use App\Media\ExportStatus;
use App\Media\Repository\ExportRepository;
use App\Security\Voter\WeddingVoter;
use App\Wedding\Entity\Wedding;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class ExportDownloadController extends AbstractController
{
    /**
     * Der Dateiname steht im Pfad, damit der Browser ihn übernimmt (Caddy liefert die Datei aus).
     */
    #[Route(
        '/admin/exports/{id}/{filename}',
        name: 'admin_export_download',
        requirements: [
            'filename' => '[a-z0-9-]+\.zip',
        ],
        methods: ['GET'],
    )]
    public function __invoke(string $id, ExportRepository $exports, ExportFiles $files, ClockInterface $clock): Response
    {
        $export = Uuid::isValid($id) ? $exports->find(Uuid::fromString($id)) : null;
        if (null === $export) {
            throw new NotFoundHttpException();
        }

        $this->denyAccessUnlessGranted(WeddingVoter::attribute(WeddingPermission::Export), $export->getWedding());

        if (
            ExportStatus::Ready !== $export->getStatus()
            || $export->isExpiredAt(DateTimeImmutable::createFromInterface($clock->now()))
            || !is_file($files->localPath($export))
        ) {
            throw new NotFoundHttpException();
        }

        return new Response('', Response::HTTP_OK, [
            'X-Accel-Redirect' => '/_protected/files/' . $files->relativePath($export),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public static function filename(Wedding $wedding): string
    {
        return $wedding->getSlug() . '-export.zip';
    }
}
