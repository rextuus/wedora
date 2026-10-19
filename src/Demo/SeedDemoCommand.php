<?php

declare(strict_types=1);

namespace App\Demo;

use App\Challenge\ChallengeTemplates;
use App\Challenge\Entity\Challenge;
use App\Guestbook\Entity\GuestbookEntry;
use App\Media\Visibility;
use App\Wedding\Entity\Wedding;
use App\Wedding\PublicLinks;
use App\Wedding\Repository\WeddingRepository;
use App\Wedding\WeddingEraser;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Baut eine komplette Vorführ-Hochzeit: Aufgabe „First Drink“, gezeichnete Porträts an,
 * Beispielgäste mit Fotos, Videos, Likes und Gästebucheinträgen.
 */
#[AsCommand(name: 'app:demo:seed', description: 'Legt eine vollständige Vorführ-Hochzeit mit Beispieldaten an')]
final readonly class SeedDemoCommand
{
    public const array GUESTBOOK = [
        'Marie' => "Was für ein wunderschöner Tag! Ihr zwei strahlt einfach.\nAuf viele gemeinsame Jahre!",
        'Oma Ilse' => 'Ich bin so stolz auf euch. Passt gut aufeinander auf.',
        'Jonas' => 'Die Tanzfläche war legendär. Danke für die beste Party des Jahres!',
    ];

    public function __construct(
        private WeddingRepository $weddings,
        private WeddingEraser $eraser,
        private DemoSeeder $seeder,
        private PublicLinks $links,
        private EntityManagerInterface $em,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Ordner mit Beispielmedien (JPEG/PNG/MP4, optionale .txt als Bildunterschrift)')]
        string $directory = 'var/demo-photos',
        #[Option(description: 'URL-Kürzel der Vorführ-Hochzeit')]
        string $slug = 'larissa-und-tim',
        #[Option(description: 'Anzeigename')]
        string $title = 'Larissa & Tim',
        #[Option(description: 'Hochzeitsdatum (YYYY-MM-DD)')]
        string $date = '2027-07-18',
        #[Option(description: 'Vorhandene Hochzeit mit diesem Kürzel vorher vollständig löschen')]
        bool $reset = false,
    ): int {
        if (!is_dir($directory)) {
            $io->error(sprintf('Ordner „%s“ nicht gefunden. Beispielmedien zuerst auf den Server kopieren (siehe docs/deployment.md).', $directory));

            return Command::FAILURE;
        }

        $existing = $this->weddings->findOneBySlug($slug);
        if (null !== $existing && !$reset) {
            $io->error(sprintf('Die Hochzeit „%s“ gibt es schon. Mit --reset wird sie vorher gelöscht.', $slug));

            return Command::FAILURE;
        }

        if (null !== $existing) {
            $this->eraser->erase($existing);
            $io->writeln(sprintf('Alte Hochzeit „%s“ gelöscht.', $slug));
        }

        $wedding = $this->createWedding($slug, $title, $date);
        $imported = $this->seeder->importDirectory($wedding, $directory, true, $io->writeln(...));
        $this->writeGuestbook($wedding);

        $io->success(sprintf('Vorführ-Hochzeit „%s“ mit %d Medien angelegt. Videos verarbeitet der Worker.', $title, count($imported)));
        $io->definitionList(
            ['QR-/Beitrittslink' => $this->links->join($wedding)],
            ['Verwaltung' => sprintf('app:user:create … und app:wedding:grant %s <e-mail> view_content moderate configure manage_access export manage_lifecycle', $slug)],
        );

        return Command::SUCCESS;
    }

    private function createWedding(string $slug, string $title, string $date): Wedding
    {
        $wedding = new Wedding($slug, $title, new DateTimeImmutable($date));
        $wedding->enablePortraits(true);

        $template = ChallengeTemplates::all()['first-drink'];
        $challenge = new Challenge($wedding, $template['title']);
        $challenge->describe($template['description']);
        $challenge->setMediaType($template['type']);
        $challenge->setPoints($template['points']);

        $this->em->persist($wedding);
        $this->em->persist($challenge);
        $this->em->flush();

        return $wedding;
    }

    private function writeGuestbook(Wedding $wedding): void
    {
        foreach ($this->seeder->guests($wedding) as $guest) {
            $message = self::GUESTBOOK[$guest->getDisplayName()] ?? null;
            if (null !== $message) {
                $this->em->persist(new GuestbookEntry($guest, $message, Visibility::AllGuests));
            }
        }

        $this->em->flush();
    }
}
