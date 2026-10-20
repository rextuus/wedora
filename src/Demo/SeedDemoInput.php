<?php

declare(strict_types=1);

namespace App\Demo;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;

/** Eingaben für app:demo:seed. */
final class SeedDemoInput
{
    #[Argument(description: 'Ordner mit Beispielmedien (JPEG/PNG/MP4, optionale .txt als Bildunterschrift)')]
    public string $directory = 'var/demo-photos';

    #[Option(description: 'URL-Kürzel der Vorführ-Hochzeit')]
    public string $slug = 'larissa-und-tim';

    #[Option(description: 'Anzeigename')]
    public string $title = 'Larissa & Tim';

    #[Option(description: 'Hochzeitsdatum (YYYY-MM-DD)')]
    public string $date = '2027-07-18';

    #[Option(description: 'Vorhandene Hochzeit mit diesem Kürzel vorher vollständig löschen')]
    public bool $reset = false;
}
