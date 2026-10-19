<?php

declare(strict_types=1);

namespace App\Media\Storage;

use App\Media\MediaId;

/**
 * Privater Medienspeicher. Kein Pfad dieses Speichers ist direkt öffentlich erreichbar;
 * ausgeliefert wird nur nach Prüfung in Symfony über den Webserver (X-Accel-Redirect).
 */
interface MediaStorage
{
    /**
     * @param resource|string $source Stream oder Pfad einer lokalen Datei
     */
    public function store(MediaId $id, MediaFile $file, mixed $source): void;

    /** Übernimmt eine lokale Datei (verschieben statt kopieren). */
    public function adopt(MediaId $id, MediaFile $file, string $localPath): void;

    public function exists(MediaId $id, MediaFile $file): bool;

    public function size(MediaId $id, MediaFile $file): int;

    /** Absoluter Pfad für Verarbeitung (ffmpeg) im Worker. */
    public function localPath(MediaId $id, MediaFile $file): string;

    /** Pfad relativ zur Speicherwurzel, für den Webserver und imgproxy. */
    public function relativePath(MediaId $id, MediaFile $file): string;

    /** Entfernt das Medium mit allen Varianten endgültig. */
    public function deleteAll(MediaId $id): void;

    /** @return list<string> vorhandene Dateien des Mediums */
    public function list(MediaId $id): array;
}
