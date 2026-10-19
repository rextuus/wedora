<?php

declare(strict_types=1);

namespace App\Wedding\Entity;

use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Medienlimits pro Hochzeit (ADR 0003). Standardwerte gelten für neue Hochzeiten.
 */
#[ORM\Embeddable]
class MediaLimits
{
    public const int DEFAULT_MAX_VIDEO_SECONDS = 120;
    public const int DEFAULT_MAX_VIDEO_BYTES = 500 * 1024 * 1024;
    public const int DEFAULT_MAX_PHOTO_BYTES = 30 * 1024 * 1024;
    public const int DEFAULT_STORAGE_QUOTA_BYTES = 25 * 1024 * 1024 * 1024;
    public const int DEFAULT_GUEST_QUOTA_BYTES = 2 * 1024 * 1024 * 1024;

    #[ORM\Column]
    private int $maxVideoSeconds = self::DEFAULT_MAX_VIDEO_SECONDS;

    #[ORM\Column(type: 'bigint')]
    private int $maxVideoBytes = self::DEFAULT_MAX_VIDEO_BYTES;

    #[ORM\Column(type: 'bigint')]
    private int $maxPhotoBytes = self::DEFAULT_MAX_PHOTO_BYTES;

    #[ORM\Column(type: 'bigint')]
    private int $storageQuotaBytes = self::DEFAULT_STORAGE_QUOTA_BYTES;

    /** null = kein Limit pro Gast. */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $guestQuotaBytes = self::DEFAULT_GUEST_QUOTA_BYTES;

    public function maxVideoSeconds(): int
    {
        return $this->maxVideoSeconds;
    }

    public function maxVideoBytes(): int
    {
        return $this->maxVideoBytes;
    }

    public function maxPhotoBytes(): int
    {
        return $this->maxPhotoBytes;
    }

    public function storageQuotaBytes(): int
    {
        return $this->storageQuotaBytes;
    }

    public function guestQuotaBytes(): ?int
    {
        return $this->guestQuotaBytes;
    }

    public function change(
        int $maxVideoSeconds,
        int $maxVideoBytes,
        int $maxPhotoBytes,
        int $storageQuotaBytes,
        ?int $guestQuotaBytes,
    ): void {
        foreach ([
            $maxVideoSeconds,
            $maxVideoBytes,
            $maxPhotoBytes,
            $storageQuotaBytes,
            $guestQuotaBytes ?? 1,
        ] as $value) {
            if ($value < 1) {
                throw new InvalidArgumentException('Limits müssen positiv sein.');
            }
        }

        $this->maxVideoSeconds = $maxVideoSeconds;
        $this->maxVideoBytes = $maxVideoBytes;
        $this->maxPhotoBytes = $maxPhotoBytes;
        $this->storageQuotaBytes = $storageQuotaBytes;
        $this->guestQuotaBytes = $guestQuotaBytes;
    }
}
