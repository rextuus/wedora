<?php

declare(strict_types=1);

namespace App\Security\Guest;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Twig\Environment;

/**
 * Ohne gültigen Gastzugang: freundlicher Hinweis statt Login-Formular.
 * Bewusst ohne Aussage, ob die Hochzeit existiert.
 */
final readonly class GuestEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        private Environment $twig,
    ) {}

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new Response($this->twig->render('guest/no_access.html.twig'), Response::HTTP_UNAUTHORIZED, [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
