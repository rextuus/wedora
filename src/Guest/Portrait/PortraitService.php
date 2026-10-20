<?php

declare(strict_types=1);

namespace App\Guest\Portrait;

use App\Ai\AiBudget;
use App\Ai\ImageGenerator;
use App\Guest\Entity\Guest;
use App\Wedding\CoupleNames;
use App\Wedding\Entity\Wedding;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Gezeichnete Gästeporträts: Selfie annehmen, Zeichnung im Hintergrund anstoßen, Porträt entfernen.
 */
final readonly class PortraitService
{
    /** Unter der Grenze des Webservers (9 MB); die App verkleinert das Foto vorher ohnehin. */
    public const int MAX_SELFIE_BYTES = 8 * 1024 * 1024;

    /** Danach gilt ein Porträt „in Arbeit“ als hängengeblieben (normal: unter 30 Sekunden). */
    public const int STALE_AFTER_MINUTES = 3;

    /** Formate, die Gemini als Vorlage annimmt. */
    public const array ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    public function __construct(
        private ImageGenerator $generator,
        private PortraitStorage $storage,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        #[Target('guest_portrait')]
        private RateLimiterFactoryInterface $limiter,
        private AiBudget $budget,
    ) {}

    public function isOfferedTo(Wedding $wedding): bool
    {
        return $wedding->arePortraitsEnabled() && $wedding->isActive() && $this->generator->isAvailable();
    }

    /**
     * @throws PortraitRejected
     */
    /**
     * @param PortraitStyle|null $wish Wunsch des Gastes; zählt nur, wenn die Hochzeit die Wahl erlaubt
     */
    /**
     * @param array{string, string}|null $answer Frage und Antwort beim Selfie („origin“, „family“); nötig, wenn Accessoires an sind
     */
    public function request(
        Guest $guest,
        UploadedFile $selfie,
        ?PortraitStyle $wish = null,
        ?array $answer = null,
    ): void {
        $wedding = $guest->getWedding();
        if (!$this->isOfferedTo($wedding)) {
            throw new PortraitRejected('Porträts sind für diese Hochzeit nicht eingeschaltet.');
        }

        $style = self::styleFor($wedding, $wish);
        [$gadget, $stored] = self::gadgetFor($wedding, $answer);

        if (PortraitStatus::Pending === $guest->getPortraitStatus()) {
            throw new PortraitRejected('Dein Porträt wird gerade gezeichnet.');
        }

        if (!$selfie->isValid() || (int) $selfie->getSize() > self::MAX_SELFIE_BYTES) {
            throw new PortraitRejected('Das Foto ist zu groß oder kam nicht vollständig an.');
        }

        // Format am Inhalt erkennen, nicht an Dateiname oder Browser-Angabe.
        $mime = mime_content_type($selfie->getPathname());
        if (!in_array($mime, self::ACCEPTED_TYPES, true)) {
            throw new PortraitRejected('Bitte ein Foto (JPEG, PNG, WebP oder HEIC) verwenden.');
        }

        if (!$this->budget->allows()) {
            throw new PortraitRejected('Das Zeichnen ist für heute leider ausgeschöpft. Schau morgen wieder vorbei!');
        }

        $limit = $this->limiter->create($guest->getId()->toRfc4122())->consume();
        if (!$limit->isAccepted()) {
            // Persönliches Limit (nicht die Kostenbremse): gilt 24 Stunden ab dem ersten Porträt.
            throw new PortraitRejected(sprintf(
                'Du hast schon %d Porträts zeichnen lassen – mehr geht pro Person innerhalb von 24 Stunden nicht. Ab %s Uhr kannst du wieder.',
                $limit->getLimit(),
                $limit
                    ->getRetryAfter()
                    ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                    ->format('H:i'),
            ));
        }

        $this->storage->keepSelfie($guest, $selfie->getPathname());
        $this->budget->record('portrait', $wedding);
        $guest->portraitRequested($this->now(), $style, $gadget, $stored);
        $this->em->flush();

        $this->bus->dispatch(new CreatePortrait($guest->getId()->toRfc4122()));
    }

    /**
     * Accessoire aus der Antwort auf die Frage beim Selfie (nur wenn Accessoires eingeschaltet sind).
     *
     * @param array{string, string}|null $answer
     *
     * @return array{PortraitGadget|null, string|null} Accessoire und gespeicherte Antwort „frage:antwort“
     *
     * @throws PortraitRejected
     */
    private static function gadgetFor(Wedding $wedding, ?array $answer): array
    {
        if (!$wedding->arePortraitGadgetsEnabled()) {
            return [null, null];
        }

        if (null === $answer) {
            throw new PortraitRejected('Bitte beantworte noch die kurze Frage.');
        }

        $gadget = PortraitQuestions::gadgetFor(CoupleNames::of($wedding), $answer[0], $answer[1]);
        if (null === $gadget) {
            throw new PortraitRejected('Bitte beantworte noch die kurze Frage.');
        }

        return [$gadget, $answer[0] . ':' . $answer[1]];
    }

    /**
     * Wahl des Gastes (falls erlaubt und angeboten), sonst Zufall aus den angebotenen Stilen.
     *
     * @throws PortraitRejected
     */
    private static function styleFor(Wedding $wedding, ?PortraitStyle $wish): PortraitStyle
    {
        $offered = $wedding->portraitStyles();
        if (!$wedding->letsGuestsChoosePortraitStyle()) {
            return $offered[array_rand($offered)];
        }

        $style = $wish ?? $offered[0];
        if (!in_array($style, $offered, true)) {
            throw new PortraitRejected('Dieser Zeichenstil wird hier nicht angeboten.');
        }

        return $style;
    }

    /**
     * Hängt ein Porträt zu lange (z. B. Worker abgestürzt), gilt es als fehlgeschlagen: Der Gast sieht
     * „Nochmal versuchen“ statt endlos „wird gezeichnet“, das Selfie wird gelöscht. Ein später doch noch
     * zugestellter Auftrag wird vom Handler ignoriert (Status ist nicht mehr „in Arbeit“).
     */
    public function expireIfStale(Guest $guest): bool
    {
        $startedAt = $guest->getPortraitUpdatedAt();
        if (
            PortraitStatus::Pending !== $guest->getPortraitStatus()
            || null === $startedAt
            || $startedAt > $this->staleBefore()
        ) {
            return false;
        }

        $this->storage->deleteSelfie($guest);
        $guest->portraitFailed($this->now());
        $this->em->flush();

        return true;
    }

    /** Alle hängenden Porträts beenden (stündlich im Aufräumen); liefert die Anzahl. */
    public function expireAllStale(): int
    {
        /** @var list<Guest> $stale */
        $stale = $this->em
            ->createQueryBuilder()
            ->select('g')
            ->from(Guest::class, 'g')
            ->where('g.portraitStatus = :pending')
            ->andWhere('g.portraitUpdatedAt < :before')
            ->setParameter('pending', PortraitStatus::Pending)
            ->setParameter('before', $this->staleBefore())
            ->getQuery()
            ->getResult();
        foreach ($stale as $guest) {
            $this->expireIfStale($guest);
        }

        return count($stale);
    }

    private function staleBefore(): DateTimeImmutable
    {
        return $this->now()->modify(sprintf('-%d minutes', self::STALE_AFTER_MINUTES));
    }

    public function remove(Guest $guest): void
    {
        $this->storage->deleteFor($guest);
        $guest->portraitRemoved();
        $this->em->flush();
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
