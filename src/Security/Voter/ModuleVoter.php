<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Security\ViewerResolver;
use App\Wedding\Entity\WeddingModule;
use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * MODULE_VIEW: Modul aktiv und Zugriff auf die Hochzeit.
 * MODULE_CONTRIBUTE: zusätzlich Hochzeit aktiv (nicht archiviert) und Beitragsfenster offen.
 *
 * @extends Voter<string, WeddingModule>
 */
final class ModuleVoter extends Voter
{
    public const string VIEW = 'MODULE_VIEW';
    public const string CONTRIBUTE = 'MODULE_CONTRIBUTE';

    public function __construct(
        private readonly ViewerResolver $viewers,
        private readonly ClockInterface $clock,
    ) {}

    #[Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof WeddingModule && in_array($attribute, [self::VIEW, self::CONTRIBUTE], true);
    }

    #[Override]
    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        #[SensitiveParameter]
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool {
        $viewer = $this->viewers->fromSecurityUser($token->getUser());
        $wedding = $subject->getWedding();
        $participant = $viewer->isGuestOf($wedding) || $viewer->isMemberOf($wedding);

        if (!$participant || !$subject->isEnabled()) {
            return false;
        }

        return self::VIEW === $attribute
        || $subject->acceptsContributionsAt(DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
