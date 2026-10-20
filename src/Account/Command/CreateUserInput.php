<?php

declare(strict_types=1);

namespace App\Account\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;

/** Eingaben für app:user:create. */
final class CreateUserInput
{
    #[Argument(description: 'E-Mail-Adresse')]
    public string $email;

    #[Argument(description: 'Anzeigename')]
    public string $name;

    #[Option(description: 'Als Plattformadministration anlegen')]
    public bool $platformAdmin = false;

    #[Option(description: 'Passwort aus der Standardeingabe lesen (für Skripte ohne Terminal)')]
    public bool $passwordStdin = false;
}
