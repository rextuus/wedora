<?php

declare(strict_types=1);

namespace App\Guest\Entity;

use App\Guest\JoinMethod;
use App\Guest\Portrait\PortraitGadget;
use App\Guest\Portrait\PortraitStatus;
use App\Guest\Portrait\PortraitStyle;
use App\Guest\Repository\GuestRepository;
use App\Wedding\Entity\Invitation;
use App\Wedding\Entity\Wedding;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use SensitiveParameter;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Gastidentität ohne Konto. Der Browser hält ein zufälliges Geheimnis (Cookie);
 * gespeichert wird nur dessen Hash. Geht das Cookie verloren, ist die Identität weg.
 */
#[ORM\Entity(repositoryClass: GuestRepository::class)]
#[ORM\Table(name: 'guest')]
class Guest
{
    public const int DISPLAY_NAME_MAX = 40;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 64)]
    private string $secretHash;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $blockedAt = null;

    #[ORM\Column(length: self::DISPLAY_NAME_MAX)]
    private string $displayName;

    /** Gezeichnetes Porträt aus einem Selfie (optional, nur mit Einwilligung). */
    #[ORM\Column(length: 16, nullable: true, enumType: PortraitStatus::class)]
    private ?PortraitStatus $portraitStatus = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $portraitUpdatedAt = null;

    #[ORM\Column(length: 16, nullable: true, enumType: PortraitStyle::class)]
    private ?PortraitStyle $portraitStyle = null;

    #[ORM\Column(length: 16, nullable: true, enumType: PortraitGadget::class)]
    private ?PortraitGadget $portraitGadget = null;

    /** Antwort auf die Frage beim Selfie als „frage:antwort“ (bestimmt das Accessoire). */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $portraitAnswer = null;

    /** Porträt für andere Gäste freigegeben (Gästewand, kleines Bild am Namen); nur auf eigenen Wunsch. */
    #[ORM\Column]
    private bool $portraitShared = false;

    private function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Wedding $wedding,
        string $displayName,
        #[SensitiveParameter]
        string $secret,
        #[ORM\Column(length: 16, enumType: JoinMethod::class)]
        private JoinMethod $joinMethod,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?Invitation $invitation,
    ) {
        $this->id = Uuid::v7();
        $this->displayName = self::normalizeName($displayName);
        $this->secretHash = self::hashSecret($secret);
        $this->createdAt = new DateTimeImmutable();
    }

    /**
     * @return array{Guest, string} Gast und Klartext-Geheimnis für das Cookie (nur jetzt verfügbar)
     */
    public static function join(Wedding $wedding, string $displayName, ?Invitation $invitation): array
    {
        if (null !== $invitation && $invitation->getWedding() !== $wedding) {
            throw new InvalidArgumentException('Einladung gehört zu einer anderen Hochzeit.');
        }

        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $method = null === $invitation ? JoinMethod::OpenLink : JoinMethod::Invitation;

        return [new self($wedding, $displayName, $secret, $method, $invitation), $secret];
    }

    public static function hashSecret(#[SensitiveParameter] string $secret): string
    {
        return hash('sha256', 'guest|' . $secret);
    }

    public static function normalizeName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $length = mb_strlen($name);

        if ($length < 1 || $length > self::DISPLAY_NAME_MAX || 1 === preg_match('/\p{C}/u', $name)) {
            throw new InvalidArgumentException('Ungültiger Anzeigename.');
        }

        return $name;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getWedding(): Wedding
    {
        return $this->wedding;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function rename(string $displayName): void
    {
        $this->displayName = self::normalizeName($displayName);
    }

    public function getJoinMethod(): JoinMethod
    {
        return $this->joinMethod;
    }

    public function getInvitation(): ?Invitation
    {
        return $this->invitation;
    }

    public function matchesSecret(#[SensitiveParameter] string $secret): bool
    {
        return hash_equals($this->secretHash, self::hashSecret($secret));
    }

    public function block(DateTimeImmutable $at): void
    {
        $this->blockedAt ??= $at;
    }

    public function unblock(): void
    {
        $this->blockedAt = null;
    }

    public function isBlocked(): bool
    {
        return null !== $this->blockedAt;
    }

    /**
     * Wird bei jeder Anfrage geprüft: gesperrte Gäste und Gäste widerrufener
     * Einladungen verlieren sofort den Zugang.
     */
    public function hasAccess(): bool
    {
        return !$this->isBlocked() && !($this->invitation?->isRevoked() ?? false);
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPortraitStatus(): ?PortraitStatus
    {
        return $this->portraitStatus;
    }

    public function hasPortrait(): bool
    {
        return PortraitStatus::Ready === $this->portraitStatus;
    }

    /** Für Cache-Busting der Bild-URL und die Anzeige „wird gezeichnet seit …“. */
    public function getPortraitUpdatedAt(): ?DateTimeImmutable
    {
        return $this->portraitUpdatedAt;
    }

    public function getPortraitStyle(): PortraitStyle
    {
        return $this->portraitStyle ?? PortraitStyle::Line;
    }

    public function getPortraitGadget(): ?PortraitGadget
    {
        return $this->portraitGadget;
    }

    public function portraitRequested(
        DateTimeImmutable $at,
        PortraitStyle $style = PortraitStyle::Line,
        ?PortraitGadget $gadget = null,
        ?string $answer = null,
    ): void {
        $this->portraitStatus = PortraitStatus::Pending;
        $this->portraitUpdatedAt = $at;
        $this->portraitStyle = $style;
        $this->portraitGadget = $gadget;
        $this->portraitAnswer = $answer;
    }

    public function getPortraitAnswer(): ?string
    {
        return $this->portraitAnswer;
    }

    public function portraitDrawn(DateTimeImmutable $at): void
    {
        $this->portraitStatus = PortraitStatus::Ready;
        $this->portraitUpdatedAt = $at;
    }

    public function portraitFailed(DateTimeImmutable $at): void
    {
        $this->portraitStatus = PortraitStatus::Failed;
        $this->portraitUpdatedAt = $at;
    }

    public function isPortraitShared(): bool
    {
        return $this->portraitShared;
    }

    public function sharePortrait(bool $shared): void
    {
        $this->portraitShared = $shared;
    }

    /** Mit dem Porträt endet auch die Freigabe; ein neues ist erst einmal wieder privat. */
    public function portraitRemoved(): void
    {
        $this->portraitShared = false;
        $this->portraitStatus = null;
        $this->portraitUpdatedAt = null;
        $this->portraitStyle = null;
        $this->portraitGadget = null;
        $this->portraitAnswer = null;
    }
}
