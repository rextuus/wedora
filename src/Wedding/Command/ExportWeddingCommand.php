<?php

declare(strict_types=1);

namespace App\Wedding\Command;

use App\Account\Repository\UserRepository;
use App\Media\Controller\ExportDownloadController;
use App\Media\Export\ExportService;
use App\Media\ExportStatus;
use App\Media\Upload\UploadService;
use App\Wedding\Repository\WeddingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsCommand(
    name: 'app:wedding:export',
    description: 'Erstellt einen ZIP-Export (Originale + Übersicht) im Hintergrund',
)]
final readonly class ExportWeddingCommand
{
    public function __construct(
        private WeddingRepository $weddings,
        private UserRepository $users,
        private ExportService $exports,
        private EntityManagerInterface $em,
        private UrlGeneratorInterface $urls,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Kürzel der Hochzeit')]
        string $slug,
        #[Argument(
            description: 'E-Mail des Kontos, das den Export anfordert (braucht das Recht „export“ zum Herunterladen)',
        )]
        string $email,
    ): int {
        $wedding = $this->weddings->findOneBySlug($slug);
        $user = $this->users->findOneByEmail($email);
        if (null === $wedding || null === $user) {
            $io->error('Hochzeit oder Konto nicht gefunden.');

            return Command::FAILURE;
        }

        $export = $this->exports->request($wedding, $user);
        $this->em->refresh($export);

        if (ExportStatus::Pending === $export->getStatus()) {
            $io->success('Export angefordert; der Worker erstellt ihn im Hintergrund.');
        } elseif (ExportStatus::Ready === $export->getStatus()) {
            $io->success(sprintf(
                'Export fertig: %d Dateien, %s.',
                $export->getItemCount(),
                UploadService::humanBytes($export->getBytes()),
            ));
        } else {
            $io->error((string) $export->getError());

            return Command::FAILURE;
        }

        $io->definitionList(['Download (nach Anmeldung)' => $this->urls->generate(
            'admin_export_download',
            [
                'id' => $export->getId()->toRfc4122(),
                'filename' => ExportDownloadController::filename($wedding),
            ],
            UrlGeneratorInterface::ABSOLUTE_URL,
        )]);

        return Command::SUCCESS;
    }
}
