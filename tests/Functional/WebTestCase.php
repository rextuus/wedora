<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Guest\Entity\Guest;
use App\Security\Guest\GuestCookie;
use App\Tests\Support\Fixtures;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use SensitiveParameter;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase as BaseWebTestCase;
use Symfony\Component\BrowserKit\Cookie;

abstract class WebTestCase extends BaseWebTestCase
{
    protected KernelBrowser $client;
    protected Fixtures $fixtures;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->fixtures = new Fixtures(self::em(...));
    }

    protected static function em(): EntityManagerInterface
    {
        return self::service(EntityManagerInterface::class);
    }

    /**
     * Lädt eine Entity aus dem aktuellen EntityManager (nach einem Request ist der alte geleert).
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    protected static function fresh(object $entity): object
    {
        $em = self::em();
        $id = $em->getClassMetadata($entity::class)->getIdentifierValues($entity);
        $found = $em->find($entity::class, $id);

        return $found ?? throw new RuntimeException('Entity nicht mehr vorhanden.');
    }

    /**
     * Setzt das Gast-Cookie so, wie es der Browser nach dem Beitritt hätte.
     */
    protected function actAsGuest(Guest $guest, #[SensitiveParameter] string $secret, ?string $path = null): void
    {
        $this->client->getCookieJar()->set(
            new Cookie(
                GuestCookie::NAME,
                $guest->getId()->toRfc4122() . '.' . $secret,
                null,
                $path ?? GuestCookie::path($guest->getWedding()->getSlug()),
            ),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected static function service(string $id): object
    {
        $service = static::getContainer()->get($id);

        return $service instanceof $id ? $service : throw new RuntimeException(sprintf('Dienst %s fehlt.', $id));
    }
}
