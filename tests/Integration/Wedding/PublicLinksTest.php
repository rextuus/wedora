<?php

declare(strict_types=1);

namespace App\Tests\Integration\Wedding;

use App\Tests\Integration\IntegrationTestCase;
use App\Wedding\Entity\Wedding;
use App\Wedding\PublicLinks;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Gedruckte Links nutzen die öffentliche Adresse; lokal gibt es zusätzlich einen Test-Link fürs Heimnetz. */
final class PublicLinksTest extends IntegrationTestCase
{
    public function testLanLinkOnlyWhenConfiguredAndDifferent(): void
    {
        self::bootKernel();
        $urls = self::service(UrlGeneratorInterface::class);
        $wedding = new Wedding('lea-und-max', 'Lea & Max');
        $token = $wedding->access()->openLinkToken();

        $local = new PublicLinks($urls, 'http://localhost:8180', 'http://192.168.178.56:8180/');
        self::assertSame("http://localhost:8180/h/lea-und-max/join?k={$token}", $local->join($wedding));
        self::assertSame("http://192.168.178.56:8180/h/lea-und-max/join?k={$token}", $local->lanJoin($wedding));

        self::assertNull(
            new PublicLinks($urls, 'https://hochzeit.example', '')->lanJoin($wedding),
            'Auf dem Server aus.',
        );
        self::assertNull(
            new PublicLinks($urls, 'http://192.168.178.56:8180', 'http://192.168.178.56:8180')->lanJoin($wedding),
            'Gleiche Adresse: kein zweiter Code.',
        );
    }
}
