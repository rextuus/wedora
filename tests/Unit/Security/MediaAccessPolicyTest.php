<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Account\WeddingPermission;
use App\Media\Entity\Media;
use App\Media\Visibility;
use App\Security\MediaAccessPolicy;
use App\Security\Viewer;
use App\Tests\Support\Fixtures;
use App\Wedding\Entity\Wedding;
use App\Wedding\ModerationMode;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Vollständige Matrix der Sichtbarkeitsregeln (Sichtbarkeit × Veröffentlichung × Betrachter).
 */
final class MediaAccessPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{Visibility, string, string, bool}>
     */
    public static function viewMatrix(): iterable
    {
        // [Sichtbarkeit, Veröffentlichungsstatus, Betrachter, erwartet]
        $cases = [
            // Für alle Gäste
            [Visibility::AllGuests,    'published', 'uploader',               true],
            [Visibility::AllGuests,    'published', 'other_guest',            true],
            [Visibility::AllGuests,    'published', 'foreign_guest',          false],
            [Visibility::AllGuests,    'published', 'viewer_member',          true],
            [Visibility::AllGuests,    'published', 'moderator',              true],
            [Visibility::AllGuests,    'published', 'member_without_content', false],
            [Visibility::AllGuests,    'published', 'platform_admin',         false],
            [Visibility::AllGuests,    'published', 'foreign_moderator',      false],
            [Visibility::AllGuests,    'published', 'anonymous',              false],
            [Visibility::AllGuests,    'pending',   'uploader',               true],
            [Visibility::AllGuests,    'pending',   'other_guest',            false],
            [Visibility::AllGuests,    'pending',   'viewer_member',          false],
            [Visibility::AllGuests,    'pending',   'moderator',              true],
            [Visibility::AllGuests,    'rejected',  'uploader',               true],
            [Visibility::AllGuests,    'rejected',  'other_guest',            false],
            [Visibility::AllGuests,    'rejected',  'moderator',              true],
            // Nur Brautpaar/Administration
            [Visibility::Couple,       'published', 'uploader',               true],
            [Visibility::Couple,       'published', 'other_guest',            false],
            [Visibility::Couple,       'published', 'viewer_member',          true],
            [Visibility::Couple,       'published', 'moderator',              true],
            [Visibility::Couple,       'published', 'platform_admin',         false],
            [Visibility::Couple,       'pending',   'viewer_member',          false],
            [Visibility::Couple,       'pending',   'moderator',              true],
            // Nur der hochladende Gast
            [Visibility::UploaderOnly, 'published', 'uploader',               true],
            [Visibility::UploaderOnly, 'published', 'other_guest',            false],
            [Visibility::UploaderOnly, 'published', 'viewer_member',          false],
            [Visibility::UploaderOnly, 'published', 'moderator',              false],
            [Visibility::UploaderOnly, 'pending',   'uploader',               true],
            [Visibility::UploaderOnly, 'pending',   'moderator',              false],
        ];

        foreach ($cases as [$visibility, $status, $viewer, $expected]) {
            yield sprintf('%s/%s/%s', $visibility->value, $status, $viewer) => [
                $visibility,
                $status,
                $viewer,
                $expected,
            ];
        }
    }

    #[DataProvider('viewMatrix')]
    public function testCanView(Visibility $visibility, string $status, string $viewerKind, bool $expected): void
    {
        [$media, $viewers] = self::scenario($visibility, $status);

        self::assertSame($expected, new MediaAccessPolicy()->canView($viewers[$viewerKind], $media));
    }

    public function testDeletedMediaIsInvisibleEvenForUploaderAndModerator(): void
    {
        [$media, $viewers] = self::scenario(Visibility::AllGuests, 'published');
        $media->markDeleted(new DateTimeImmutable());
        $policy = new MediaAccessPolicy();

        foreach (['uploader', 'other_guest', 'moderator', 'viewer_member'] as $kind) {
            self::assertFalse($policy->canView($viewers[$kind], $media), $kind);
            self::assertFalse($policy->canDelete($viewers[$kind], $media), $kind);
        }
    }

    public function testUnfinishedOrFailedMediaIsOnlyVisibleToUploader(): void
    {
        $policy = new MediaAccessPolicy();

        foreach (['processing', 'failed'] as $state) {
            [$media, $viewers] = self::scenario(Visibility::AllGuests, 'published', ready: false);
            if ('failed' === $state) {
                $media->markFailed('kaputt');
            }

            self::assertTrue($policy->canView($viewers['uploader'], $media), $state);
            foreach (['other_guest', 'viewer_member', 'moderator'] as $kind) {
                self::assertFalse($policy->canView($viewers[$kind], $media), "{$state}/{$kind}");
            }
        }
    }

    public function testModerationAndDeletionRights(): void
    {
        [$media, $viewers] = self::scenario(Visibility::AllGuests, 'published');
        [$private] = self::scenario(Visibility::UploaderOnly, 'published');
        $policy = new MediaAccessPolicy();

        self::assertTrue($policy->canModerate($viewers['moderator'], $media));
        self::assertFalse($policy->canModerate($viewers['viewer_member'], $media));
        self::assertFalse($policy->canModerate($viewers['uploader'], $media));
        self::assertFalse($policy->canModerate($viewers['platform_admin'], $media));

        self::assertTrue($policy->canDelete($viewers['uploader'], $media));
        self::assertTrue($policy->canDelete($viewers['moderator'], $media));
        self::assertFalse($policy->canDelete($viewers['other_guest'], $media));
        self::assertFalse(
            $policy->canModerate($viewers['moderator'], $private),
            '„Nur für mich“ ist der Moderation entzogen.',
        );
    }

    public function testGuestIdFromAnotherWeddingDoesNotCountAsUploader(): void
    {
        [$media, $viewers] = self::scenario(Visibility::UploaderOnly, 'published');
        $uploaderId = $media->getUploaderGuest()?->getId();
        self::assertNotNull($uploaderId);
        $otherWedding = new Fixtures()->wedding();

        // Gleiche Gast-ID, aber im Kontext einer anderen Hochzeit (manipulierte Identität).
        $spoofed = Viewer::guest($uploaderId, $otherWedding->getId());

        self::assertFalse(new MediaAccessPolicy()->canView($spoofed, $media));
        self::assertTrue(new MediaAccessPolicy()->canView($viewers['uploader'], $media));
    }

    /**
     * @return array{Media, array<string, Viewer>}
     */
    private static function scenario(Visibility $visibility, string $status, bool $ready = true): array
    {
        $fixtures = new Fixtures();
        $wedding = $fixtures->wedding(ModerationMode::Approval);
        $foreignWedding = $fixtures->wedding();
        [$uploader] = $fixtures->guest($wedding, 'Uploader');
        [$other] = $fixtures->guest($wedding, 'Andere');
        [$foreign] = $fixtures->guest($foreignWedding, 'Fremd');
        $media = $fixtures->guestMedia($uploader, $visibility, ready: $ready);

        match ($status) {
            'published' => $media->approve(new DateTimeImmutable()),
            'rejected' => $media->reject(new DateTimeImmutable()),
            default => null,
        };

        $member = static fn(Wedding $w, WeddingPermission ...$p): Viewer => Viewer::user(
            new Fixtures()->user()->getId(),
            false,
            [$w->getId()->toRfc4122() => array_values($p)],
        );

        return [
            $media,
            [
                'uploader' => Viewer::guest($uploader->getId(), $wedding->getId()),
                'other_guest' => Viewer::guest($other->getId(), $wedding->getId()),
                'foreign_guest' => Viewer::guest($foreign->getId(), $foreignWedding->getId()),
                'viewer_member' => $member($wedding, WeddingPermission::ViewContent),
                'moderator' => $member($wedding, WeddingPermission::Moderate),
                'member_without_content' => $member($wedding, WeddingPermission::Configure, WeddingPermission::Export),
                'foreign_moderator' => $member(
                    $foreignWedding,
                    WeddingPermission::Moderate,
                    WeddingPermission::ViewContent,
                ),
                'platform_admin' => Viewer::user(
                    new Fixtures()
                        ->user(true)
                        ->getId(),
                    true,
                    [],
                ),
                'anonymous' => Viewer::anonymous(),
            ],
        ];
    }
}
