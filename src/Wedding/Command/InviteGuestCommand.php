<?php

declare(strict_types=1);

namespace App\Wedding\Command;

use App\Wedding\Entity\Invitation;
use App\Wedding\PublicLinks;
use App\Wedding\Repository\WeddingRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:wedding:invite', description: 'Erzeugt einen persönlichen, widerrufbaren Einladungslink')]
final readonly class InviteGuestCommand
{
    public function __construct(
        private WeddingRepository $weddings,
        private EntityManagerInterface $em,
        private PublicLinks $links,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Kürzel der Hochzeit')]
        string $slug,
        #[Argument(description: 'Bezeichnung, z. B. „Familie Müller“')]
        string $label,
        #[Option(description: 'Gültig bis (z. B. 2026-12-31 oder "+30 days")')]
        ?string $expires = null,
    ): int {
        $wedding = $this->weddings->findOneBySlug($slug);
        if (null === $wedding) {
            $io->error('Hochzeit nicht gefunden.');

            return Command::FAILURE;
        }

        [$invitation, $token] = Invitation::create(
            $wedding,
            $label,
            null === $expires ? null : new DateTimeImmutable($expires),
        );
        $this->em->persist($invitation);
        $this->em->flush();

        $io->success('Einladung erstellt. Der Link wird nur jetzt angezeigt.');
        $io->definitionList(['ID' => $invitation->getId()->toRfc4122()], ['Link' => $this->links->invitation($token)]);

        return Command::SUCCESS;
    }
}
