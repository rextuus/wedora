<?php

declare(strict_types=1);

namespace App\Guest;

enum JoinMethod: string
{
    /** Offener Link/QR-Code, ggf. zusätzlich mit Hochzeitspasswort. */
    case OpenLink = 'open_link';

    /** Persönlicher, widerrufbarer Einladungslink. */
    case Invitation = 'invitation';
}
