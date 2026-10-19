<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media;

use App\Media\Access\MediaAccessTokenSigner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class MediaAccessTokenSignerTest extends TestCase
{
    // @mago-expect lint:no-literal-password
    private const string SECRET = 'unit-test-secret-0123456789abcdef0123';

    public function testIssuedTokenVerifiesWithMediaAndSubject(): void
    {
        $signer = new MediaAccessTokenSigner(self::SECRET, new MockClock('2026-10-19 12:00:00'), 900);

        $token = $signer->verify($signer->issue('asset-1', 'guest-42'));

        self::assertNotNull($token);
        self::assertSame('asset-1', $token->mediaId);
        self::assertSame('guest-42', $token->subject);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $clock = new MockClock('2026-10-19 12:00:00');
        $signer = new MediaAccessTokenSigner(self::SECRET, $clock, 900);
        $issued = $signer->issue('asset-1', 'guest-42');

        $clock->modify('+901 seconds');

        self::assertNull($signer->verify($issued));
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $signer = new MediaAccessTokenSigner(self::SECRET, new MockClock(), 900);
        [, $signature] = explode('.', $signer->issue('asset-1', 'guest-42'));
        $forgedPayload = rtrim(strtr(base64_encode('asset-2|guest-42|9999999999'), '+/', '-_'), '=');

        self::assertNull($signer->verify($forgedPayload . '.' . $signature));
    }

    public function testTokenFromOtherSecretIsRejected(): void
    {
        $clock = new MockClock();
        $foreign = new MediaAccessTokenSigner('another-secret-0123456789abcdef01234', $clock);

        self::assertNull(new MediaAccessTokenSigner(self::SECRET, $clock)->verify($foreign->issue(
            'asset-1',
            'guest-42',
        )));
    }

    public function testMalformedTokensAreRejected(): void
    {
        $signer = new MediaAccessTokenSigner(self::SECRET, new MockClock());

        self::assertNull($signer->verify(''));
        self::assertNull($signer->verify('abc'));
        self::assertNull($signer->verify('a.b.c'));
    }

    public function testShortSecretIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MediaAccessTokenSigner('too-short', new MockClock());
    }
}
