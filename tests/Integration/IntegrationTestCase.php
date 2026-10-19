<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class IntegrationTestCase extends KernelTestCase
{
    /**
     * Typsicherer Zugriff auf Dienste im Test-Container.
     *
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
