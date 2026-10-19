<?php

declare(strict_types=1);

namespace App\Account\Entity;

use App\Account\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use SensitiveParameter;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Konto für Plattform- und Hochzeitsadministration (Gäste haben kein Konto).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(length: 255)]
    private string $password = '';

    #[ORM\Column]
    private bool $platformAdmin = false;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(
        string $email,
        #[ORM\Column(length: 80)]
        private string $displayName,
    ) {
        $this->id = Uuid::v7();
        $this->email = mb_strtolower(trim($email));
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getUserIdentifier(): string
    {
        return '' === $this->email ? $this->id->toRfc4122() : $this->email;
    }

    public function getRoles(): array
    {
        return $this->platformAdmin ? ['ROLE_USER', 'ROLE_PLATFORM_ADMIN'] : ['ROLE_USER'];
    }

    public function isPlatformAdmin(): bool
    {
        return $this->platformAdmin;
    }

    public function makePlatformAdmin(bool $platformAdmin = true): void
    {
        $this->platformAdmin = $platformAdmin;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(#[SensitiveParameter] string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    /**
     * Passwort-Hash nicht in der Session speichern.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        // Symfony-Empfehlung: Array-Cast erhält die internen Schlüssel privater Eigenschaften.
        /** @var array<string, mixed> $data */
        // @mago-expect analysis:invalid-type-cast
        $data = (array) $this;
        $data["\0" . self::class . "\0password"] = hash('crc32c', $this->password);

        return $data;
    }
}
