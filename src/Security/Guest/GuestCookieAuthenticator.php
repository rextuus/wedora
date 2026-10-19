<?php

declare(strict_types=1);

namespace App\Security\Guest;

use App\Guest\Repository\GuestRepository;
use App\Wedding\Entity\Wedding;
use Override;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authentifiziert Gäste bei jeder Anfrage unter /h/{slug} anhand des Gast-Cookies.
 *
 * Geprüft wird jedes Mal gegen die Datenbank: Hochzeit passt zum Pfad, Geheimnis stimmt,
 * Gast nicht gesperrt, Einladung nicht widerrufen. Bei Fehlschlag wird das Cookie entfernt.
 */
final class GuestCookieAuthenticator extends AbstractAuthenticator
{
    public const string CLEAR_COOKIE_ATTRIBUTE = '_wedora_clear_guest_cookie';

    public function __construct(
        private readonly GuestRepository $guests,
    ) {}

    #[Override]
    public function supports(Request $request): bool
    {
        return null !== self::slug($request) && $request->cookies->has(GuestCookie::NAME);
    }

    #[Override]
    public function authenticate(Request $request): Passport
    {
        $slug = (string) self::slug($request);
        $credential = GuestCookie::parse((string) $request->cookies->get(GuestCookie::NAME, ''));
        $guest = null === $credential ? null : $this->guests->find($credential[0]);

        if (
            null === $credential
            || null === $guest
            || $guest->getWedding()->getSlug() !== $slug
            || !$guest->matchesSecret($credential[1])
            || !$guest->hasAccess()
        ) {
            throw new BadCredentialsException('Gastzugang ungültig.');
        }

        return new SelfValidatingPassport(
            new UserBadge('guest:' . $guest->getId()->toRfc4122(), static fn(): GuestUser => new GuestUser($guest)),
        );
    }

    #[Override]
    public function onAuthenticationSuccess(
        Request $request,
        #[SensitiveParameter]
        TokenInterface $token,
        string $firewallName,
    ): ?Response {
        return null;
    }

    #[Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->attributes->set(self::CLEAR_COOKIE_ATTRIBUTE, self::slug($request));

        return null;
    }

    private static function slug(Request $request): ?string
    {
        return 1 === preg_match('#^/h/(' . Wedding::SLUG_PATTERN . ')(?:/|$)#', $request->getPathInfo(), $m)
            ? $m[1]
            : null;
    }
}
