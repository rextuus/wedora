<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Account\Entity\WeddingMember;
use App\Account\WeddingPermission;
use App\Guest\Entity\Guest;
use App\Guest\JoinMethod;
use App\Media\Entity\Media;
use App\Media\MediaType;
use App\Media\PublicationStatus;
use App\Media\Visibility;
use App\Tests\Support\Fixtures;
use App\Wedding\Entity\Wedding;
use App\Wedding\ModerationMode;
use App\Wedding\ModuleType;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class WeddingDomainTest extends TestCase
{
    #[TestWith(['ab'])]
    #[TestWith(['Anna-Lukas'])]
    #[TestWith(['-anna'])]
    #[TestWith(['anna-'])]
    #[TestWith(['anna/../x'])]
    #[TestWith(['anna lukas'])]
    public function testInvalidSlugsAreRejected(string $slug): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Wedding($slug, 'Test');
    }

    public function testNewWeddingHasAllModulesDefaultLimitsAndOpenLink(): void
    {
        $wedding = new Wedding('anna-und-lukas', 'Anna & Lukas');

        self::assertCount(count(ModuleType::cases()), $wedding->enabledModules());
        self::assertSame(120, $wedding->limits()->maxVideoSeconds());
        self::assertSame(25 * (1024 ** 3), $wedding->limits()->storageQuotaBytes());
        self::assertSame(2 * (1024 ** 3), $wedding->limits()->guestQuotaBytes());
        self::assertTrue($wedding->access()->acceptsOpenLinkToken($wedding->access()->openLinkToken()));
        self::assertFalse($wedding->access()->acceptsOpenLinkToken('falsch'));
        self::assertFalse($wedding->access()->requiresPassword());
    }

    public function testOpenLinkCanBeDisabledAndRotated(): void
    {
        $access = new Wedding('anna-und-lukas', 'Anna & Lukas')->access();
        $old = $access->openLinkToken();

        $access->rotateOpenLinkToken();
        self::assertFalse($access->acceptsOpenLinkToken($old));
        self::assertTrue($access->acceptsOpenLinkToken($access->openLinkToken()));

        $access->enableOpenLink(false);
        self::assertFalse($access->acceptsOpenLinkToken($access->openLinkToken()));
    }

    public function testLimitsMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Wedding('anna-und-lukas', 'Anna & Lukas')
            ->limits()
            ->change(0, 1, 1, 1, null);
    }

    public function testInvitationLifecycle(): void
    {
        $fixtures = new Fixtures();
        $wedding = $fixtures->wedding();
        $now = new DateTimeImmutable('2027-07-18 12:00');
        [$invitation] = $fixtures->invitation($wedding, 'Familie', new DateTimeImmutable('2027-07-19'));

        self::assertTrue($invitation->canBeUsedToJoinAt($now));
        self::assertFalse($invitation->canBeUsedToJoinAt(new DateTimeImmutable('2027-07-20')), 'abgelaufen');

        $wedding->access()->enableInvitations(false);
        self::assertFalse($invitation->canBeUsedToJoinAt($now), 'Einladungen deaktiviert');
        $wedding->access()->enableInvitations(true);

        $wedding->archive();
        self::assertFalse($invitation->canBeUsedToJoinAt($now), 'Hochzeit archiviert');
        $wedding->reactivate();

        $invitation->revoke($now);
        self::assertFalse($invitation->canBeUsedToJoinAt($now), 'widerrufen');
    }

    public function testInvitationTokenIsOnlyStoredAsHash(): void
    {
        [$invitation, $token] = new Fixtures()->invitation(new Fixtures()->wedding());

        self::assertNotSame($token, Guest::hashSecret($token));
        self::assertStringNotContainsString($token, serialize($invitation));
    }

    public function testRevokedInvitationAndBlockRemoveGuestAccess(): void
    {
        $fixtures = new Fixtures();
        $wedding = $fixtures->wedding();
        [$invitation] = $fixtures->invitation($wedding);
        [$guest, $secret] = $fixtures->guest($wedding, 'Sophie', $invitation);

        self::assertSame(JoinMethod::Invitation, $guest->getJoinMethod());
        self::assertTrue($guest->matchesSecret($secret));
        self::assertFalse($guest->matchesSecret($secret . 'x'));
        self::assertTrue($guest->hasAccess());

        $invitation->revoke(new DateTimeImmutable());
        self::assertFalse($guest->hasAccess());

        [$openGuest] = $fixtures->guest($wedding, 'Max');
        $openGuest->block(new DateTimeImmutable());
        self::assertFalse($openGuest->hasAccess());
        $openGuest->unblock();
        self::assertTrue($openGuest->hasAccess());
    }

    public function testInvitationOfAnotherWeddingCannotBeUsed(): void
    {
        $fixtures = new Fixtures();
        [$invitation] = $fixtures->invitation($fixtures->wedding());

        $this->expectException(InvalidArgumentException::class);
        Guest::join($fixtures->wedding(), 'Sophie', $invitation);
    }

    #[TestWith(['  Sophie   Maria ', 'Sophie Maria'])]
    #[TestWith(['Ömer', 'Ömer'])]
    public function testDisplayNameIsNormalized(string $input, string $expected): void
    {
        self::assertSame($expected, Guest::normalizeName($input));
    }

    #[TestWith([''])]
    #[TestWith(['   '])]
    #[TestWith(["So\u{0000}phie"])]
    #[TestWith(["So\u{0007}phie"])]
    #[TestWith(["Sophie\u{202E}"])]
    public function testInvalidDisplayNamesAreRejected(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        Guest::normalizeName($name);
    }

    public function testTooLongDisplayNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Guest::normalizeName(str_repeat('a', Guest::DISPLAY_NAME_MAX + 1));
    }

    /**
     * @return iterable<string, array{ModerationMode, PublicationStatus}>
     */
    public static function moderationModes(): iterable
    {
        yield 'sofort' => [ModerationMode::Immediate, PublicationStatus::Published];
        yield 'nach Freigabe' => [ModerationMode::Approval, PublicationStatus::Pending];
        yield 'nachträgliche Moderation' => [ModerationMode::PostModeration, PublicationStatus::Published];
    }

    #[DataProvider('moderationModes')]
    public function testInitialPublicationStatusFollowsModerationMode(
        ModerationMode $mode,
        PublicationStatus $expected,
    ): void {
        $fixtures = new Fixtures();
        [$guest] = $fixtures->guest($fixtures->wedding($mode));

        $media = $fixtures->guestMedia($guest);

        self::assertSame($expected, $media->getPublicationStatus());
        self::assertNull($media->getReviewedAt());
    }

    public function testPrivateUploadsSkipApproval(): void
    {
        $fixtures = new Fixtures();
        [$guest] = $fixtures->guest($fixtures->wedding(ModerationMode::Approval));

        self::assertSame(
            PublicationStatus::Published,
            $fixtures->guestMedia($guest, Visibility::UploaderOnly)->getPublicationStatus(),
        );
        self::assertSame(
            PublicationStatus::Pending,
            $fixtures->guestMedia($guest, Visibility::Couple)->getPublicationStatus(),
        );
    }

    public function testUploaderOnlyVisibilityIsReservedForGuests(): void
    {
        $fixtures = new Fixtures();

        $this->expectException(InvalidArgumentException::class);
        Media::uploadedByUser(
            $fixtures->user(),
            $fixtures->wedding(),
            ModuleType::Gallery,
            MediaType::Photo,
            Visibility::UploaderOnly,
        );
    }

    public function testModuleContributionWindow(): void
    {
        $wedding = new Wedding('anna-und-lukas', 'Anna & Lukas');
        $module = $wedding->module(ModuleType::Challenges);
        $now = new DateTimeImmutable('2027-07-18 23:00');

        self::assertTrue($module->acceptsContributionsAt($now));

        $module->closeContributionsAt(new DateTimeImmutable('2027-07-18 22:00'));
        self::assertFalse($module->acceptsContributionsAt($now), 'Beitragsfenster geschlossen');

        $module->closeContributionsAt(null);
        $module->setEnabled(false);
        self::assertFalse($module->acceptsContributionsAt($now), 'Modul deaktiviert');

        $module->setEnabled(true);
        $wedding->archive();
        self::assertFalse($module->acceptsContributionsAt($now), 'Archiv ist nur lesend');
    }

    public function testMemberPermissionsAreNormalized(): void
    {
        $fixtures = new Fixtures();
        $member = new WeddingMember(
            $fixtures->user(),
            $fixtures->wedding(),
            [
                WeddingPermission::Moderate,
                WeddingPermission::ViewContent,
                WeddingPermission::Moderate,
            ],
        );

        self::assertSame([WeddingPermission::Moderate, WeddingPermission::ViewContent], $member->getPermissions());
    }
}
