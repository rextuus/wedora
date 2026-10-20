<?php

declare(strict_types=1);

namespace App\Wedding\Entity;

use App\Wedding\CoverMode;
use App\Wedding\Host\HostPronoun;
use App\Wedding\Host\WeddingPicture;
use App\Wedding\NudgeMode;
use App\Wedding\PhotoLook;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Wie sich die Hochzeit den Gästen zeigt: wer den Server betreibt und was für einer es ist
 * (Vertrauenshinweis „Wo liegen meine Fotos?“) sowie das Titelbild der Startseite.
 */
#[ORM\Embeddable]
class Presentation
{
    /** Wer den Server betreibt (z. B. ein Gast). */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $hostName = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $hostNote = null;

    /** Zeitpunkt des Fotos der Betreiberin/des Betreibers (null = kein Foto). */
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $hostPhotoAt = null;

    /** „Sprich ihn/sie gern an“; ohne Angabe neutral. */
    #[ORM\Column(length: 8, nullable: true, enumType: HostPronoun::class)]
    private ?HostPronoun $hostPronoun = null;

    /** Was für ein Server und wo er steht (z. B. „ein ThinkCentre in seiner Wohnung in Köln-Ehrenfeld“). */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $serverNote = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $serverPhotoAt = null;

    /** Bild oben auf der Startseite: neuestes Foto, festes Titelbild oder keins. */
    #[ORM\Column(length: 8, enumType: CoverMode::class, options: ['default' => 'latest'])]
    private CoverMode $coverMode = CoverMode::Latest;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $coverPhotoAt = null;

    /** Gemeinsamer Look aller Fotos (nur Anzeige). */
    #[ORM\Column(length: 8, enumType: PhotoLook::class, options: ['default' => 'natural'])]
    private PhotoLook $photoLook = PhotoLook::Natural;

    /** Figur „Zu viel am Handy?“: taucht nach einiger Zeit oder vielen Beiträgen auf – oder gar nicht. */
    #[ORM\Column(length: 8, enumType: NudgeMode::class, options: ['default' => 'time'])]
    private NudgeMode $nudgeMode = NudgeMode::Time;

    /** Minuten (Zeit) bzw. Beiträge pro Stunde (Aktivität). */
    #[ORM\Column(options: ['default' => 20])]
    private int $nudgeThreshold = 20;

    public function getHostName(): ?string
    {
        return $this->hostName;
    }

    public function getHostNote(): ?string
    {
        return $this->hostNote;
    }

    public function getHostPhotoAt(): ?DateTimeImmutable
    {
        return $this->hostPhotoAt;
    }

    public function getHostPronoun(): ?HostPronoun
    {
        return $this->hostPronoun;
    }

    public function describeHost(?string $name, ?string $note, ?HostPronoun $pronoun = null): void
    {
        $name = trim((string) $name);
        $note = trim((string) $note);
        if (mb_strlen($name) > 60 || mb_strlen($note) > 160) {
            throw new InvalidArgumentException('Name höchstens 60, Satz höchstens 160 Zeichen.');
        }

        $this->hostName = '' === $name ? null : $name;
        $this->hostNote = '' === $note ? null : $note;
        $this->hostPronoun = $pronoun;
    }

    public function getServerNote(): ?string
    {
        return $this->serverNote;
    }

    public function getServerPhotoAt(): ?DateTimeImmutable
    {
        return $this->serverPhotoAt;
    }

    public function describeServer(?string $note): void
    {
        $note = trim((string) $note);
        if (mb_strlen($note) > 200) {
            throw new InvalidArgumentException('Die Beschreibung des Servers darf höchstens 200 Zeichen haben.');
        }

        $this->serverNote = '' === $note ? null : $note;
    }

    public function getCoverMode(): CoverMode
    {
        return $this->coverMode;
    }

    public function getCoverPhotoAt(): ?DateTimeImmutable
    {
        return $this->coverPhotoAt;
    }

    public function chooseCover(CoverMode $mode): void
    {
        $this->coverMode = $mode;
    }

    /** Das feste Titelbild gilt nur, wenn auch eines hochgeladen ist; sonst das neueste Foto. */
    public function showsFixedCover(): bool
    {
        return CoverMode::Fixed === $this->coverMode && null !== $this->coverPhotoAt;
    }

    public function getPhotoLook(): PhotoLook
    {
        return $this->photoLook;
    }

    public function choosePhotoLook(PhotoLook $look): void
    {
        $this->photoLook = $look;
    }

    public function getNudgeMode(): NudgeMode
    {
        return $this->nudgeMode;
    }

    public function getNudgeThreshold(): int
    {
        return $this->nudgeThreshold;
    }

    /** Ohne (sinnvolle) Zahl gilt der Vorschlag der Art: 20 Minuten bzw. 10 Beiträge. */
    public function configureNudge(NudgeMode $mode, int $threshold): void
    {
        if ($threshold > 600) {
            throw new InvalidArgumentException('Höchstens 600 Minuten bzw. Beiträge.');
        }

        $this->nudgeMode = $mode;
        $this->nudgeThreshold = match (true) {
            NudgeMode::Off === $mode => 0,
            $threshold > 0 => $threshold,
            default => $mode->defaultThreshold(),
        };
    }

    public function pictureAt(WeddingPicture $picture): ?DateTimeImmutable
    {
        return match ($picture) {
            WeddingPicture::Host => $this->hostPhotoAt,
            WeddingPicture::Server => $this->serverPhotoAt,
            WeddingPicture::Cover => $this->coverPhotoAt,
        };
    }

    public function pictureChanged(WeddingPicture $picture, ?DateTimeImmutable $at): void
    {
        match ($picture) {
            WeddingPicture::Host => $this->hostPhotoAt = $at,
            WeddingPicture::Server => $this->serverPhotoAt = $at,
            WeddingPicture::Cover => $this->coverPhotoAt = $at,
        };
    }
}
