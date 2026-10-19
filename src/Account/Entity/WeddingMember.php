<?php

declare(strict_types=1);

namespace App\Account\Entity;

use App\Account\Repository\WeddingMemberRepository;
use App\Account\WeddingPermission;
use App\Wedding\Entity\Wedding;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Hochzeitsadministration: ein Konto mit explizit vergebenen Rechten für genau eine Hochzeit.
 */
#[ORM\Entity(repositoryClass: WeddingMemberRepository::class)]
#[ORM\Table(name: 'wedding_member')]
#[ORM\UniqueConstraint(name: 'uniq_wedding_member', columns: ['user_id', 'wedding_id'])]
class WeddingMember
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $permissions = [];

    /**
     * @param list<WeddingPermission> $permissions
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Wedding $wedding,
        array $permissions,
        /** z. B. „Brautpaar“, „Trauzeugin“ – nur Anzeige, keine Rechte. */
        #[ORM\Column(length: 60)]
        private string $label = '',
    ) {
        $this->id = Uuid::v7();
        $this->setPermissions($permissions);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getWedding(): Wedding
    {
        return $this->wedding;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @return list<WeddingPermission>
     */
    public function getPermissions(): array
    {
        return array_values(array_filter(array_map(WeddingPermission::tryFrom(...), $this->permissions)));
    }

    /**
     * @param list<WeddingPermission> $permissions
     */
    public function setPermissions(array $permissions): void
    {
        $values = array_map(static fn(WeddingPermission $p): string => $p->value, $permissions);
        sort($values);
        $this->permissions = array_values(array_unique($values));
    }
}
