<?php

declare(strict_types=1);

namespace App\Media\Controller;

use App\Guest\Entity\Guest;
use App\Security\Guest\GuestUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Gemeinsame Regeln der Gast-API: nur angemeldete Gäste, und schreibende Anfragen nur
 * vom eigenen Frontend (eigener Header erzwingt bei fremden Seiten einen CORS-Preflight,
 * den wir nicht erlauben; zusätzlich Prüfung von Origin/Sec-Fetch-Site).
 */
abstract class GuestApiController extends AbstractController
{
    public const string CSRF_HEADER = 'X-Requested-With';
    public const string CSRF_VALUE = 'wedora';

    protected function guest(Request $request): Guest
    {
        $user = $this->getUser();
        if (!$user instanceof GuestUser) {
            throw new AccessDeniedHttpException();
        }

        if (!$request->isMethodSafe() && !self::isSameOrigin($request)) {
            throw new AccessDeniedHttpException('Ungültige Herkunft der Anfrage.');
        }

        return $user->guest;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected static function apiResponse(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store']);
    }

    protected static function error(string $message, int $status): JsonResponse
    {
        return self::apiResponse(['error' => $message], $status);
    }

    private static function isSameOrigin(Request $request): bool
    {
        $origin = $request->headers->get('Origin');
        $site = $request->headers->get('Sec-Fetch-Site');

        return (
            self::CSRF_VALUE === $request->headers->get(self::CSRF_HEADER)
            && (null === $origin || $origin === $request->getSchemeAndHttpHost())
            && (null === $site || in_array($site, ['same-origin', 'none'], true))
        );
    }
}
