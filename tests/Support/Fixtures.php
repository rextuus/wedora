<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Account\Entity\User;
use App\Account\Entity\WeddingMember;
use App\Account\WeddingPermission;
use App\Guest\Entity\Guest;
use App\Media\Entity\Media;
use App\Media\MediaType;
use App\Media\Visibility;
use App\Wedding\Entity\Invitation;
use App\Wedding\Entity\Wedding;
use App\Wedding\ModerationMode;
use App\Wedding\ModuleType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Baut Testdaten; mit EntityManager werden sie gespeichert, ohne bleiben sie im Speicher.
 */
final class Fixtures
{
    private static int $counter = 0;

    /**
     * @param (\Closure(): EntityManagerInterface)|null $em liefert den aktuellen EntityManager
     *                                                    (der Testbrowser startet den Kernel zwischen Requests neu)
     */
    public function __construct(
        private readonly ?\Closure $em = null,
    ) {}

    public function wedding(ModerationMode $mode = ModerationMode::Immediate, ?string $slug = null): Wedding
    {
        $wedding = new Wedding(
            $slug ?? 'hochzeit-' . ++self::$counter . '-' . bin2hex(random_bytes(3)),
            'Anna & Lukas',
        );
        $wedding->setModerationMode($mode);

        return $this->save($wedding);
    }

    /**
     * @return array{Guest, string}
     */
    public function guest(Wedding $wedding, string $name = 'Sophie', ?Invitation $invitation = null): array
    {
        [$guest, $secret] = Guest::join($wedding, $name, $invitation);
        $this->save($guest);

        return [$guest, $secret];
    }

    /**
     * @return array{Invitation, string}
     */
    public function invitation(
        Wedding $wedding,
        string $label = 'Familie Müller',
        ?\DateTimeImmutable $expiresAt = null,
    ): array {
        [$invitation, $token] = Invitation::create($wedding, $label, $expiresAt);
        $this->save($invitation);

        return [$invitation, $token];
    }

    public function user(bool $platformAdmin = false): User
    {
        $user = new User('konto' . ++self::$counter . '-' . bin2hex(random_bytes(3)) . '@wedora.test', 'Konto');
        $user->setPassword('nicht-verwendet');
        $user->makePlatformAdmin($platformAdmin);

        return $this->save($user);
    }

    /**
     * @param list<WeddingPermission> $permissions
     */
    public function member(User $user, Wedding $wedding, array $permissions): WeddingMember
    {
        return $this->save(new WeddingMember($user, $wedding, $permissions));
    }

    public function guestMedia(
        Guest $guest,
        Visibility $visibility = Visibility::AllGuests,
        MediaType $type = MediaType::Photo,
        bool $ready = true,
    ): Media {
        $media = Media::uploadedByGuest($guest, ModuleType::Gallery, $type, $visibility);
        if ($ready) {
            $media->markReady();
        }

        return $this->save($media);
    }

    public function flush(): void
    {
        if (null !== $this->em) {
            ($this->em)()->flush();
        }
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function save(object $entity): object
    {
        if (null !== $this->em) {
            $em = ($this->em)();
            $em->persist($entity);
            $em->flush();
        }

        return $entity;
    }
}
