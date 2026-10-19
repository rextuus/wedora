<?php

declare(strict_types=1);

namespace App\Guest;

use App\Wedding\Entity\Invitation;
use App\Wedding\Entity\Wedding;
use App\Wedding\Repository\InvitationRepository;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Zwischenstand des Beitritts in der Session (Link geprüft → ggf. Passwort → Name).
 * Läuft nach 30 Minuten ab; ohne gültigen Zwischenstand gibt es keinen Namensschritt.
 */
final readonly class JoinFlow
{
    private const int TTL_SECONDS = 1800;

    public function __construct(
        private RequestStack $requests,
        private InvitationRepository $invitations,
        private ClockInterface $clock,
    ) {}

    public function startWithOpenLink(Wedding $wedding): void
    {
        $this->store($wedding, null, $wedding->access()->requiresPassword());
    }

    public function startWithInvitation(Invitation $invitation): void
    {
        // Persönliche Einladungen sind selbst ein Geheimnis und umgehen das Hochzeitspasswort.
        $this->store($invitation->getWedding(), $invitation, false);
    }

    public function needsPassword(Wedding $wedding): bool
    {
        return true === ($this->state($wedding)['needsPassword'] ?? false);
    }

    public function passwordVerified(Wedding $wedding): void
    {
        $state = $this->state($wedding);
        if (null !== $state) {
            $state['needsPassword'] = false;
            $this->requests->getSession()->set(self::key($wedding), $state);
        }
    }

    /**
     * Bereit für den Namensschritt? Liefert die Einladung (oder null beim offenen Link).
     *
     * @return array{ready: bool, invitation: ?Invitation}
     */
    public function readiness(Wedding $wedding): array
    {
        $state = $this->state($wedding);
        if (null === $state || $state['needsPassword']) {
            return ['ready' => false, 'invitation' => null];
        }

        if (null === $state['invitation']) {
            return ['ready' => $wedding->access()->isOpenLinkEnabled(), 'invitation' => null];
        }

        $invitation = $this->invitations->find(Uuid::fromString($state['invitation']));
        $usable = null !== $invitation && $invitation->canBeUsedToJoinAt($this->now());

        return ['ready' => $usable, 'invitation' => $usable ? $invitation : null];
    }

    public function clear(Wedding $wedding): void
    {
        $this->requests->getSession()->remove(self::key($wedding));
    }

    private function store(Wedding $wedding, ?Invitation $invitation, bool $needsPassword): void
    {
        $this->requests->getSession()->set(self::key($wedding), [
            'invitation' => $invitation?->getId()->toRfc4122(),
            'needsPassword' => $needsPassword,
            'expiresAt' => $this->now()->getTimestamp() + self::TTL_SECONDS,
        ]);
    }

    /**
     * @return array{invitation: ?string, needsPassword: bool, expiresAt: int}|null
     */
    private function state(Wedding $wedding): ?array
    {
        // @mago-expect analysis:mixed-assignment
        $state = $this->requests->getSession()->get(self::key($wedding));

        if (
            !is_array($state)
            || !array_key_exists('expiresAt', $state)
            || !is_int($state['expiresAt'])
            || $state['expiresAt'] < $this->now()->getTimestamp()
        ) {
            return null;
        }

        $invitation = array_key_exists('invitation', $state) && is_string($state['invitation'])
            ? $state['invitation']
            : null;

        return [
            'invitation' => null !== $invitation && Uuid::isValid($invitation) ? $invitation : null,
            'needsPassword' => true === ($state['needsPassword'] ?? true),
            'expiresAt' => $state['expiresAt'],
        ];
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }

    private static function key(Wedding $wedding): string
    {
        return 'join_flow.' . $wedding->getId()->toRfc4122();
    }
}
