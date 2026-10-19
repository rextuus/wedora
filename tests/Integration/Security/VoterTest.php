<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Account\Entity\User;
use App\Account\WeddingPermission;
use App\Guest\Entity\Guest;
use App\Security\Guest\GuestUser;
use App\Security\Voter\ModuleVoter;
use App\Security\Voter\WeddingVoter;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\Fixtures;
use App\Wedding\ModuleType;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final class VoterTest extends IntegrationTestCase
{
    private Fixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->fixtures = new Fixtures(
            static fn(): EntityManagerInterface => self::service(EntityManagerInterface::class),
        );
    }

    public function testWeddingPermissionsAreExplicitAndScopedToOneWedding(): void
    {
        $wedding = $this->fixtures->wedding();
        $other = $this->fixtures->wedding();
        $couple = $this->fixtures->user();
        $this->fixtures->member($couple, $wedding, [WeddingPermission::ViewContent, WeddingPermission::Moderate]);
        $voter = self::service(WeddingVoter::class);
        $token = self::userToken($couple);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $wedding, [WeddingVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $wedding, ['WEDDING_MODERATE']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $wedding, ['WEDDING_CONFIGURE']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $wedding, ['WEDDING_EXPORT']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $other, [WeddingVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $other, ['WEDDING_MODERATE']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, $wedding, ['WEDDING_UNBEKANNT']));
    }

    public function testPlatformAdminManagesButDoesNotSeeContent(): void
    {
        $wedding = $this->fixtures->wedding();
        $voter = self::service(WeddingVoter::class);
        $token = self::userToken($this->fixtures->user(true));

        foreach ([
            WeddingPermission::Configure,
            WeddingPermission::ManageAccess,
            WeddingPermission::ManageLifecycle,
        ] as $p) {
            self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $wedding, [WeddingVoter::attribute(
                $p,
            )]), $p->value);
        }

        foreach ([WeddingPermission::ViewContent, WeddingPermission::Moderate, WeddingPermission::Export] as $p) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $wedding, [WeddingVoter::attribute(
                $p,
            )]), $p->value);
        }
    }

    public function testGuestsCanViewOnlyTheirWeddingAndHaveNoAdminPermissions(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest] = $this->fixtures->guest($wedding);
        $voter = self::service(WeddingVoter::class);
        $token = self::guestToken($guest);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $wedding, [WeddingVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(
            $token,
            $this->fixtures->wedding(),
            [WeddingVoter::VIEW],
        ));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $wedding, ['WEDDING_MODERATE']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), $wedding, [WeddingVoter::VIEW]));
    }

    public function testModuleAccessFollowsActivationWindowAndArchive(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest] = $this->fixtures->guest($wedding);
        $module = $wedding->module(ModuleType::Challenges);
        $voter = self::service(ModuleVoter::class);
        $token = self::guestToken($guest);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $module, [ModuleVoter::CONTRIBUTE]));

        $module->closeContributionsAt(new DateTimeImmutable('-1 minute'));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $module, [ModuleVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $module, [ModuleVoter::CONTRIBUTE]));

        $module->closeContributionsAt(null);
        $wedding->archive();
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, $module, [ModuleVoter::VIEW]),
            'Archiv bleibt lesbar',
        );
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $module, [ModuleVoter::CONTRIBUTE]));

        $wedding->reactivate();
        $module->setEnabled(false);
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $module, [ModuleVoter::VIEW]));

        $module->setEnabled(true);
        [$foreign] = $this->fixtures->guest($this->fixtures->wedding());
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(
            self::guestToken($foreign),
            $module,
            [ModuleVoter::VIEW],
        ));
    }

    private static function userToken(User $user): TokenInterface
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    private static function guestToken(Guest $guest): TokenInterface
    {
        $user = new GuestUser($guest);

        return new PostAuthenticationToken($user, 'guest', $user->getRoles());
    }
}
