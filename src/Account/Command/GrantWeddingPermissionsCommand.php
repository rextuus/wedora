<?php

declare(strict_types=1);

namespace App\Account\Command;

use App\Account\Entity\WeddingMember;
use App\Account\Repository\UserRepository;
use App\Account\Repository\WeddingMemberRepository;
use App\Account\WeddingPermission;
use App\Wedding\Repository\WeddingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Nur die Plattformadministration (Serverzugang) vergibt Rechte für Hochzeiten.
 */
#[AsCommand(
    name: 'app:wedding:grant',
    description: 'Vergibt einem Konto Rechte für eine Hochzeit (ersetzt bestehende)',
)]
final readonly class GrantWeddingPermissionsCommand
{
    public function __construct(
        private UserRepository $users,
        private WeddingRepository $weddings,
        private WeddingMemberRepository $members,
        private EntityManagerInterface $em,
    ) {}

    /**
     * @param list<string> $permissions
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Kürzel der Hochzeit')]
        string $slug,
        #[Argument(description: 'E-Mail des Kontos')]
        string $email,
        #[Argument(description: 'Rechte: view_content moderate configure manage_access export manage_lifecycle')]
        array $permissions,
        #[Option(description: 'Bezeichnung, z. B. „Brautpaar“')]
        string $label = '',
    ): int {
        $wedding = $this->weddings->findOneBySlug($slug);
        $user = $this->users->findOneByEmail($email);
        if (null === $wedding || null === $user) {
            $io->error('Hochzeit oder Konto nicht gefunden.');

            return Command::FAILURE;
        }

        $granted = [];
        foreach ($permissions as $value) {
            $permission = WeddingPermission::tryFrom($value);
            if (null === $permission) {
                $io->error(sprintf('Unbekanntes Recht „%s“.', $value));

                return Command::INVALID;
            }

            $granted[] = $permission;
        }

        $member = $this->members->findOneFor($user, $wedding);
        if (null === $member) {
            $this->em->persist(new WeddingMember($user, $wedding, $granted, $label));
        } else {
            $member->setPermissions($granted);
        }

        $this->em->flush();
        $io->success(sprintf(
            '%s hat für „%s“ jetzt: %s',
            $user->getEmail(),
            $wedding->getTitle(),
            implode(', ', $permissions),
        ));

        return Command::SUCCESS;
    }
}
