<?php

declare(strict_types=1);

namespace App\Wedding\Command;

use App\Wedding\Repository\InvitationRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:wedding:revoke-invitation',
    description: 'Widerruft eine Einladung; zugehörige Gäste verlieren sofort den Zugang',
)]
final readonly class RevokeInvitationCommand
{
    public function __construct(
        private InvitationRepository $invitations,
        private EntityManagerInterface $em,
    ) {}

    public function __invoke(SymfonyStyle $io, #[Argument(description: 'ID der Einladung')] string $id): int
    {
        $invitation = Uuid::isValid($id) ? $this->invitations->find(Uuid::fromString($id)) : null;
        if (null === $invitation) {
            $io->error('Einladung nicht gefunden.');

            return Command::FAILURE;
        }

        $invitation->revoke(new DateTimeImmutable());
        $this->em->flush();
        $io->success(sprintf('Einladung „%s“ widerrufen.', $invitation->getLabel()));

        return Command::SUCCESS;
    }
}
