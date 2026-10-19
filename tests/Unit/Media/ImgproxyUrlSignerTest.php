<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media;

use App\Media\Image\ImgproxyUrlSigner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ImgproxyUrlSignerTest extends TestCase
{
    /**
     * Referenzwerte aus der imgproxy-Dokumentation („Signing the URL“), gegengeprüft mit openssl.
     */
    public function testMatchesDocumentedReferenceSignature(): void
    {
        $signer = new ImgproxyUrlSigner('736563726574', '68656C6C6F');
        $path = '/rs:fill:300:400:0/g:sm/aHR0cDovL2V4YW1w/bGUuY29tL2ltYWdl/cy9jdXJpb3NpdHku/anBn.png';

        self::assertSame('/oKfUtW34Dvo2BGQehJFR4Nr0_rIjOtdtzJ3QFsUcXH8' . $path, $signer->signPath($path));
    }

    public function testSignBuildsBase64UrlSourcePath(): void
    {
        $signer = new ImgproxyUrlSigner('736563726574', '68656C6C6F');

        self::assertSame(
            $signer->signPath('/rs:fit:600:0/bG9jYWw6Ly8vYWIvYS5qcGc.webp'),
            $signer->sign('local:///ab/a.jpg', ['rs:fit:600:0']),
        );
    }

    public function testInvalidHexIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ImgproxyUrlSigner('not-hex', 'abcd');
    }
}
