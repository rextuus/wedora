<?php

declare(strict_types=1);

namespace App\Account\Command;

use App\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:set-password',
    description: 'Setzt das Passwort eines Verwaltungskontos neu (verdeckt abgefragt)',
)]
final readonly class SetPasswordCommand
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Argument(description: 'E-Mail-Adresse des Kontos')]
        string $email,
        #[Option(description: 'Passwort aus der Standardeingabe lesen (für Skripte ohne Terminal)')]
        bool $passwordStdin = false,
    ): int {
        $user = $this->users->findOneByEmail($email);
        if (null === $user) {
            $io->error('Kein Konto mit dieser E-Mail-Adresse.');

            return Command::FAILURE;
        }

        $password = PasswordPrompt::read($io, $input, $passwordStdin, 'Neues Passwort (mind. 12 Zeichen)');
        if (mb_strlen($password) < 12) {
            $io->error('Das Passwort muss mindestens 12 Zeichen haben.');

            return Command::INVALID;
        }

        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->flush();
        $io->success(sprintf('Passwort für %s geändert.', $user->getEmail()));

        return Command::SUCCESS;
    }
}
