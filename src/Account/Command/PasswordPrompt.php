<?php

declare(strict_types=1);

namespace App\Account\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Passwort verdeckt abfragen oder – für Skripte ohne Terminal – aus der Standardeingabe lesen. */
final class PasswordPrompt
{
    public static function read(SymfonyStyle $io, InputInterface $input, bool $fromStdin, string $question): string
    {
        if ($fromStdin) {
            $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
            $content = stream_get_contents($stream ?? STDIN);

            return false === $content ? '' : trim($content);
        }

        // @mago-expect analysis:mixed-assignment
        $answer = $io->askHidden($question);

        return is_string($answer) ? $answer : '';
    }
}
