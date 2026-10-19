<?php

declare(strict_types=1);

namespace App\Security\Guest;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

#[AsEventListener]
final class ClearInvalidGuestCookieListener
{
    public function __invoke(ResponseEvent $event): void
    {
        $slug = $event->getRequest()->attributes->getString(GuestCookieAuthenticator::CLEAR_COOKIE_ATTRIBUTE);

        if ('' !== $slug) {
            $event->getResponse()->headers->setCookie(GuestCookie::clear($slug));
        }
    }
}
