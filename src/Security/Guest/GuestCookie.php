<?php

declare(strict_types=1);

namespace App\Security\Guest;

use App\Guest\Entity\Guest;
use DateTimeImmutable;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Das Gast-Cookie gilt nur für den Pfad der eigenen Hochzeit (/h/{slug}), ist HttpOnly
 * und für JavaScript unsichtbar. Inhalt: Gast-ID und zufälliges Geheimnis.
 */
final class GuestCookie
{
    public const string NAME = 'wedora_guest';

    /** Maximum, das Browser akzeptieren (400 Tage). */
    private const string LIFETIME = '+400 days';

    public static function create(Guest $guest, #[SensitiveParameter] string $secret, Request $request): Cookie
    {
        return Cookie::create(self::NAME)
            ->withValue($guest->getId()->toRfc4122() . '.' . $secret)
            ->withExpires(new DateTimeImmutable(self::LIFETIME))
            ->withPath(self::path($guest->getWedding()->getSlug()))
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }

    public static function clear(string $slug): Cookie
    {
        return Cookie::create(self::NAME)
            ->withValue('')
            ->withExpires(1)
            ->withPath(self::path($slug))
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }

    /**
     * @return array{Uuid, string}|null
     */
    public static function parse(#[SensitiveParameter] string $value): ?array
    {
        [$id, $secret] = array_pad(explode('.', $value, 2), 2, '');

        if (!Uuid::isValid($id) || 1 !== preg_match('/^[A-Za-z0-9_-]{43}$/D', $secret)) {
            return null;
        }

        return [Uuid::fromString($id), $secret];
    }

    public static function path(string $slug): string
    {
        return '/h/' . $slug;
    }
}
