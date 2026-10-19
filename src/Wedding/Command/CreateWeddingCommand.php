<?php

declare(strict_types=1);

namespace App\Wedding\Command;

use App\Wedding\Entity\Wedding;
use App\Wedding\PublicLinks;
use App\Wedding\Repository\WeddingRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

#[AsCommand(name: 'app:wedding:create', description: 'Legt eine Hochzeit an und zeigt den QR-/Beitrittslink')]
final readonly class CreateWeddingCommand
{
    public function __construct(
        private WeddingRepository $weddings,
        private EntityManagerInterface $em,
        private PasswordHasherFactoryInterface $hashers,
        private PublicLinks $links,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'URL-Kürzel, z. B. anna-und-lukas')]
        string $slug,
        #[Argument(description: 'Anzeigename, z. B. „Anna & Lukas“')]
        string $title,
        #[Option(description: 'Hochzeitsdatum (YYYY-MM-DD)')]
        ?string $date = null,
        #[Option(description: 'Gemeinsames Hochzeitspasswort abfragen')]
        bool $withPassword = false,
    ): int {
        if (null !== $this->weddings->findOneBySlug($slug)) {
            $io->error('Dieses Kürzel ist bereits vergeben.');

            return Command::FAILURE;
        }

        try {
            $wedding = new Wedding($slug, $title, null === $date ? null : new DateTimeImmutable($date));
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        if ($withPassword) {
            // @mago-expect analysis:mixed-assignment
            $password = $io->askHidden('Hochzeitspasswort');
            if (!is_string($password) || mb_strlen($password) < 6) {
                $io->error('Das Passwort muss mindestens 6 Zeichen haben.');

                return Command::INVALID;
            }

            $wedding->access()->setPasswordHash($this->hashers->getPasswordHasher('wedding_password')->hash($password));
        }

        $this->em->persist($wedding);
        $this->em->flush();

        $io->success(sprintf('Hochzeit „%s“ angelegt.', $wedding->getTitle()));
        $io->definitionList(['QR-/Beitrittslink' => $this->links->join($wedding)]);

        return Command::SUCCESS;
    }
}
