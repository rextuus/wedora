<?php

declare(strict_types=1);

namespace App\Security;

use App\Account\WeddingPermission;
use App\Wedding\Entity\Wedding;
use Symfony\Component\Uid\Uuid;

/**
 * Wer gerade zugreift – Gast, Konto oder niemand. Wird pro Anfrage frisch aus der
 * Datenbank aufgebaut (ViewerResolver), damit Sperren und Widerrufe sofort wirken.
 */
final readonly class Viewer
{
    /**
     * @param array<string, list<WeddingPermission>> $permissionsByWedding
     */
    private function __construct(
        public ?Uuid $guestId,
        public ?Uuid $guestWeddingId,
        public ?Uuid $userId,
        public bool $platformAdmin,
        private array $permissionsByWedding,
    ) {}

    public static function anonymous(): self
    {
        return new self(null, null, null, false, []);
    }

    public static function guest(Uuid $guestId, Uuid $weddingId): self
    {
        return new self($guestId, $weddingId, null, false, []);
    }

    /**
     * @param array<string, list<WeddingPermission>> $permissionsByWedding
     */
    public static function user(Uuid $userId, bool $platformAdmin, array $permissionsByWedding): self
    {
        return new self(null, null, $userId, $platformAdmin, $permissionsByWedding);
    }

    public function isGuest(): bool
    {
        return null !== $this->guestId;
    }

    public function isGuestOf(Wedding $wedding): bool
    {
        return null !== $this->guestWeddingId && $this->guestWeddingId->equals($wedding->getId());
    }

    public function isMemberOf(Wedding $wedding): bool
    {
        return array_key_exists($wedding->getId()->toRfc4122(), $this->permissionsByWedding);
    }

    public function has(WeddingPermission $permission, Wedding $wedding): bool
    {
        $granted = $this->permissionsByWedding[$wedding->getId()->toRfc4122()] ?? [];

        return (
            in_array($permission, $granted, true)
            || $this->platformAdmin && in_array($permission, WeddingPermission::platformAdminDefaults(), true)
        );
    }

    /** Für signierte Medien-URLs: kompakte, eindeutige Kennung. */
    public function subject(): ?string
    {
        return match (true) {
            null !== $this->guestId => 'guest:' . $this->guestId->toRfc4122(),
            null !== $this->userId => 'user:' . $this->userId->toRfc4122(),
            default => null,
        };
    }
}
