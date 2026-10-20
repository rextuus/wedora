<?php

declare(strict_types=1);

namespace App\Challenge;

use App\Guest\Entity\Guest;

/**
 * Bildbeschreibung fürs gemeinsame Foto bei „Foto mit gezeichnetem Gast“: beide Namen und ein lustiger Spruch,
 * z. B. „Karl und Mareike – Dreamteam“. Ohne KI; der Gast kann sie danach ändern.
 */
final class BuddyCaption
{
    public const array PHRASES = [
        'Dreamteam',
        'Beginn einer Romanze?!',
        'Paaaaarty!',
        'Beste Freunde seit genau jetzt',
        'Das Duo des Abends',
        'Partners in Crime',
        'Hier entsteht eine Legende',
        'Zwei Herzen, ein Foto',
        'Tanzpartner gesucht – und gefunden',
        'Unzertrennlich (zumindest für dieses Foto)',
        'Gezeichnet, gesucht, gefunden',
        'Seelenverwandte auf den ersten Blick',
        'Und wer seid ihr nochmal?',
        'Die nächste Hochzeit?',
        'Schnappschuss des Jahrhunderts',
        'Prost auf euch zwei!',
        'Das Foto, das man später rahmt',
        'Zufall? Wir glauben nicht.',
    ];

    public static function for(Guest $seeker, Guest $partner): string
    {
        return sprintf(
            '%s und %s – %s',
            $seeker->getDisplayName(),
            $partner->getDisplayName(),
            self::PHRASES[array_rand(self::PHRASES)],
        );
    }
}
