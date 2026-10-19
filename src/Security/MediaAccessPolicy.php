<?php

declare(strict_types=1);

namespace App\Security;

use App\Account\WeddingPermission;
use App\Media\Contribution;
use App\Media\PublicationStatus;
use App\Media\Visibility;

/**
 * Zentrale Regeln, wer ein Medium sehen, moderieren oder löschen darf.
 *
 * Sichtbarkeit und Veröffentlichung sind getrennt:
 * - Uploader sehen ihre eigenen Beiträge immer (auch unveröffentlicht/abgelehnt).
 * - Gäste sehen fremde Beiträge nur, wenn sie für alle Gäste sichtbar UND veröffentlicht sind.
 * - Konten mit „view_content“ sehen veröffentlichte Beiträge für alle Gäste und für das Brautpaar.
 * - Konten mit „moderate“ sehen zusätzlich unveröffentlichte und abgelehnte – außer „nur für mich“.
 * - „Nur für mich“ sieht ausschließlich der hochladende Gast.
 * - Noch nicht fertig verarbeitete oder fehlgeschlagene Medien sieht nur der Uploader.
 * - Plattformadministration ohne Mitgliedschaft sieht keine Inhalte.
 */
final readonly class MediaAccessPolicy
{
    public function canView(Viewer $viewer, Contribution $item): bool
    {
        if ($item->isDeleted()) {
            return false;
        }

        if (self::isUploader($viewer, $item)) {
            return true;
        }

        // Noch in Verarbeitung oder fehlgeschlagen: nur für den Uploader (Statusanzeige).
        if (Visibility::UploaderOnly === $item->getVisibility() || !$item->isReady()) {
            return false;
        }

        $wedding = $item->getWedding();
        $published = PublicationStatus::Published === $item->getPublicationStatus();

        if ($viewer->isGuestOf($wedding)) {
            return $published && Visibility::AllGuests === $item->getVisibility();
        }

        return (
            $viewer->has(WeddingPermission::Moderate, $wedding)
            || $published && $viewer->has(WeddingPermission::ViewContent, $wedding)
        );
    }

    public function canModerate(Viewer $viewer, Contribution $item): bool
    {
        return !$item->isDeleted()
        && Visibility::UploaderOnly !== $item->getVisibility()
        && $viewer->has(WeddingPermission::Moderate, $item->getWedding());
    }

    public function canDelete(Viewer $viewer, Contribution $item): bool
    {
        return !$item->isDeleted() && (self::isUploader($viewer, $item) || $this->canModerate($viewer, $item));
    }

    private static function isUploader(Viewer $viewer, Contribution $item): bool
    {
        $guest = $item->getUploaderGuest();
        if (null !== $guest && null !== $viewer->guestId) {
            return $guest->getId()->equals($viewer->guestId) && $viewer->isGuestOf($item->getWedding());
        }

        $user = $item->getUploaderUser();

        return null !== $user && null !== $viewer->userId && $user->getId()->equals($viewer->userId);
    }
}
