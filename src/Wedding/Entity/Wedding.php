<?php

declare(strict_types=1);

namespace App\Wedding\Entity;

use App\Guest\Portrait\PortraitStyle;
use App\Guest\Portrait\PortraitStyleMode;
use App\Wedding\ModerationMode;
use App\Wedding\ModuleType;
use App\Wedding\Repository\WeddingRepository;
use App\Wedding\WeddingStatus;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WeddingRepository::class)]
#[ORM\Table(name: 'wedding')]
class Wedding
{
    public const string SLUG_PATTERN = '[a-z0-9](?:[a-z0-9-]{1,62}[a-z0-9])?';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 64, unique: true)]
    private string $slug;

    #[ORM\Column(length: 16, enumType: WeddingStatus::class)]
    private WeddingStatus $status = WeddingStatus::Active;

    #[ORM\Column(length: 16, enumType: ModerationMode::class)]
    private ModerationMode $moderationMode = ModerationMode::Immediate;

    #[ORM\Embedded(class: AccessPolicy::class, columnPrefix: 'access_')]
    private AccessPolicy $access;

    #[ORM\Embedded(class: MediaLimits::class, columnPrefix: 'limit_')]
    private MediaLimits $limits;

    /** @var Collection<int, WeddingModule> */
    #[ORM\OneToMany(targetEntity: WeddingModule::class, mappedBy: 'wedding', cascade: ['persist'], orphanRemoval: true)]
    private Collection $modules;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    /** Punkte und Rangliste für Aufgaben (optional, Konzept: „Punkte und Ranglisten optional“). */
    #[ORM\Column]
    private bool $challengePointsEnabled = false;

    /** Gäste dürfen ein Selfie per KI (Gemini) zu einer Linienzeichnung umwandeln lassen. Aus, bis das Brautpaar es einschaltet. */
    #[ORM\Column]
    private bool $portraitsEnabled = false;

    /** @var list<string> angebotene Zeichenstile (Werte von PortraitStyle) */
    #[ORM\Column(type: 'json')]
    private array $portraitStyles = ['line'];

    #[ORM\Column(length: 8, enumType: PortraitStyleMode::class, options: ['default' => 'random'])]
    private PortraitStyleMode $portraitStyleMode = PortraitStyleMode::Random;

    /** Überraschungs-Accessoire (Krone, Federboa, Herz-Tattoo …) im Porträt; welches, bestimmt eine Frage beim Selfie. */
    #[ORM\Column(options: ['default' => true])]
    private bool $portraitGadgets = true;

    #[ORM\Embedded(class: Presentation::class, columnPrefix: false)]
    private Presentation $presentation;

    /** Ergebnisse der Umfrage für Gäste anzeigen (nach der eigenen Stimme). */
    #[ORM\Column]
    private bool $pollLiveResults = true;

    public function __construct(
        string $slug,
        #[ORM\Column(length: 120)]
        private string $title,
        #[ORM\Column(type: 'date_immutable', nullable: true)]
        private ?DateTimeImmutable $weddingDate = null,
    ) {
        if (1 !== preg_match('/^' . self::SLUG_PATTERN . '$/D', $slug)) {
            throw new InvalidArgumentException('Slug: 3–64 Zeichen, Kleinbuchstaben, Ziffern, Bindestrich.');
        }

        $this->id = Uuid::v7();
        $this->slug = $slug;
        $this->presentation = new Presentation();
        $this->access = new AccessPolicy();
        $this->limits = new MediaLimits();
        $this->modules = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();

        foreach (ModuleType::cases() as $type) {
            $this->modules->add(new WeddingModule($this, $type));
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function rename(string $title): void
    {
        $this->title = $title;
    }

    public function getWeddingDate(): ?DateTimeImmutable
    {
        return $this->weddingDate;
    }

    public function setWeddingDate(?DateTimeImmutable $date): void
    {
        $this->weddingDate = $date;
    }

    public function getStatus(): WeddingStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return WeddingStatus::Active === $this->status;
    }

    public function archive(): void
    {
        $this->status = WeddingStatus::Archived;
    }

    public function reactivate(): void
    {
        $this->status = WeddingStatus::Active;
    }

    public function getModerationMode(): ModerationMode
    {
        return $this->moderationMode;
    }

    public function setModerationMode(ModerationMode $mode): void
    {
        $this->moderationMode = $mode;
    }

    public function access(): AccessPolicy
    {
        return $this->access;
    }

    public function limits(): MediaLimits
    {
        return $this->limits;
    }

    public function module(ModuleType $type): WeddingModule
    {
        foreach ($this->modules as $module) {
            if ($module->getType() === $type) {
                return $module;
            }
        }

        $module = new WeddingModule($this, $type);
        $this->modules->add($module);

        return $module;
    }

    /**
     * @return list<WeddingModule>
     */
    public function enabledModules(): array
    {
        return array_values($this->modules->filter(static fn(WeddingModule $m): bool => $m->isEnabled())->toArray());
    }

    public function areChallengePointsEnabled(): bool
    {
        return $this->challengePointsEnabled;
    }

    public function enableChallengePoints(bool $enabled): void
    {
        $this->challengePointsEnabled = $enabled;
    }

    public function arePortraitsEnabled(): bool
    {
        return $this->portraitsEnabled;
    }

    public function enablePortraits(bool $enabled): void
    {
        $this->portraitsEnabled = $enabled;
    }

    /**
     * @return list<PortraitStyle> mindestens ein Stil (ohne Auswahl: feine Linien)
     */
    public function portraitStyles(): array
    {
        $styles = array_values(array_filter(array_map(PortraitStyle::tryFrom(...), $this->portraitStyles)));

        return [] === $styles ? [PortraitStyle::Line] : $styles;
    }

    public function arePortraitGadgetsEnabled(): bool
    {
        return $this->portraitGadgets;
    }

    public function enablePortraitGadgets(bool $enabled): void
    {
        $this->portraitGadgets = $enabled;
    }

    public function getPortraitStyleMode(): PortraitStyleMode
    {
        return $this->portraitStyleMode;
    }

    public function decidePortraitStylesBy(PortraitStyleMode $mode): void
    {
        $this->portraitStyleMode = $mode;
    }

    /** Darf der Gast den Stil wählen (und gibt es überhaupt eine Wahl)? */
    public function letsGuestsChoosePortraitStyle(): bool
    {
        return PortraitStyleMode::Choice === $this->portraitStyleMode && count($this->portraitStyles()) > 1;
    }

    /**
     * @param list<PortraitStyle> $styles
     */
    public function offerPortraitStyles(array $styles): void
    {
        $values = array_values(array_unique(array_map(static fn(PortraitStyle $s): string => $s->value, $styles)));
        $this->portraitStyles = [] === $values ? [PortraitStyle::Line->value] : $values;
    }

    /** Gastgeber, Server und Titelbild (siehe Presentation). */
    public function getPresentation(): Presentation
    {
        return $this->presentation;
    }

    public function showsPollLiveResults(): bool
    {
        return $this->pollLiveResults;
    }

    public function showPollLiveResults(bool $show): void
    {
        $this->pollLiveResults = $show;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
