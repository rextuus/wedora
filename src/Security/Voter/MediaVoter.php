<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Media\Contribution;
use App\Security\MediaAccessPolicy;
use App\Security\ViewerResolver;
use Override;
use SensitiveParameter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Gilt für alle Beiträge (Medien und Gästebucheinträge).
 *
 * @extends Voter<string, Contribution>
 */
final class MediaVoter extends Voter
{
    public const string VIEW = 'MEDIA_VIEW';
    public const string MODERATE = 'MEDIA_MODERATE';
    public const string DELETE = 'MEDIA_DELETE';

    public function __construct(
        private readonly ViewerResolver $viewers,
        private readonly MediaAccessPolicy $policy,
    ) {}

    #[Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Contribution
        && in_array($attribute, [self::VIEW, self::MODERATE, self::DELETE], true);
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

        return match ($attribute) {
            self::VIEW => $this->policy->canView($viewer, $subject),
            self::MODERATE => $this->policy->canModerate($viewer, $subject),
            default => $this->policy->canDelete($viewer, $subject),
        };
    }
}
