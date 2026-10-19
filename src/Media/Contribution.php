<?php

declare(strict_types=1);

namespace App\Media;

use App\Account\Entity\User;
use App\Guest\Entity\Guest;
use App\Wedding\Entity\Wedding;

/**
 * Gemeinsame Sicht auf Beiträge (Medien, Gästebucheinträge) für Sichtbarkeits- und Moderationsregeln.
 */
interface Contribution
{
    public function getWedding(): Wedding;

    public function getVisibility(): Visibility;

    public function getPublicationStatus(): PublicationStatus;

    public function isDeleted(): bool;

    /** Technisch fertig (bei Texten immer). */
    public function isReady(): bool;

    public function getUploaderGuest(): ?Guest;

    public function getUploaderUser(): ?User;
}
