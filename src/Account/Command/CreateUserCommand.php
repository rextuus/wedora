<?php

declare(strict_types=1);

namespace App\Account\Command;

use App\Account\Entity\User;
use App\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:user:create', description: 'Legt ein Verwaltungskonto an (Passwort wird verdeckt abgefragt)')]
final readonly class CreateUserCommand
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'E-Mail-Adresse')]
        string $email,
        #[Argument(description: 'Anzeigename')]
        string $name,
        #[Option(description: 'Als Plattformadministration anlegen')]
        bool $platformAdmin = false,
    ): int {
        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Ungültige E-Mail-Adresse.');

            return Command::INVALID;
        }

        if (null !== $this->users->findOneByEmail($email)) {
            $io->error('Es gibt bereits ein Konto mit dieser E-Mail-Adresse.');

            return Command::FAILURE;
        }

        // @mago-expect analysis:mixed-assignment
        $password = $io->askHidden('Passwort (mind. 12 Zeichen)');
        if (!is_string($password) || mb_strlen($password) < 12) {
            $io->error('Das Passwort muss mindestens 12 Zeichen haben.');

            return Command::INVALID;
        }

        $user = new User($email, $name);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $user->makePlatformAdmin($platformAdmin);
        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf(
            'Konto %s angelegt%s.',
            $user->getEmail(),
            $platformAdmin ? ' (Plattformadministration)' : '',
        ));

        return Command::SUCCESS;
    }
}
