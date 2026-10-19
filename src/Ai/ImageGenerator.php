<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Erzeugt Bilder per KI aus einem Text und optionalen Vorlagebildern.
 *
 * Achtung Datenschutz: Vorlagebilder verlassen den Server und gehen an den Anbieter.
 * Aufrufer holen dafür vorher die Einwilligung ein und speichern Vorlagen nicht länger als nötig.
 */
interface ImageGenerator
{
    /** Ob ein Anbieter konfiguriert ist (ohne API-Schlüssel bleibt die Funktion verborgen). */
    public function isAvailable(): bool;

    /**
     * @param list<ImageInput> $images Vorlagebilder, z. B. ein Foto, das umgezeichnet werden soll
     *
     * @throws ImageGenerationFailed
     */
    public function generate(string $prompt, array $images = [], string $aspectRatio = '1:1'): GeneratedImage;
}
