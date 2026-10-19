<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Account\WeddingPermission;
use App\Security\ViewerResolver;
use App\Wedding\Entity\Wedding;
use Override;
use SensitiveParameter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * WEDDING_VIEW: Gast dieser Hochzeit, Mitglied oder Plattformadministration.
 * WEDDING_<RECHT>: z. B. WEDDING_MODERATE – explizit vergebenes Recht (siehe WeddingPermission).
 *
 * @extends Voter<string, Wedding>
 */
final class WeddingVoter extends Voter
{
    public const string VIEW = 'WEDDING_VIEW';

    public function __construct(
        private readonly ViewerResolver $viewers,
    ) {}

    public static function attribute(WeddingPermission $permission): string
    {
        return 'WEDDING_' . strtoupper($permission->value);
    }

    #[Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Wedding && (self::VIEW === $attribute || null !== self::permission($attribute));
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

        if (self::VIEW === $attribute) {
            return $viewer->isGuestOf($subject) || $viewer->isMemberOf($subject) || $viewer->platformAdmin;
        }

        $permission = self::permission($attribute);

        return null !== $permission && $viewer->has($permission, $subject);
    }

    private static function permission(string $attribute): ?WeddingPermission
    {
        return str_starts_with($attribute, 'WEDDING_')
            ? WeddingPermission::tryFrom(strtolower(substr($attribute, strlen('WEDDING_'))))
            : null;
    }
}
