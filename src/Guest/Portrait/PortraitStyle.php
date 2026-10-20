<?php

declare(strict_types=1);

namespace App\Guest\Portrait;

/**
 * Zeichenstile für Gästeporträts. „Feine Linien“ wird transparent gespeichert und per CSS in der
 * Akzentfarbe eingefärbt; die übrigen Stile sind farbige Bilder und erscheinen rund zugeschnitten.
 */
enum PortraitStyle: string
{
    case Line = 'line';
    case Watercolor = 'watercolor';
    case Pencil = 'pencil';
    case Comic = 'comic';
    case PopArt = 'popart';

    private const string SUBJECT =
        'Redraw the main person in this photo as a portrait illustration. '
            . 'Head and shoulders only, cropped just below the shoulders, centered, facing the viewer, the figure filling about two thirds of the image; '
            . 'leave out arms, hands, a raised selfie arm, phones and everything in the background. '
            . 'Keep the recognizable hairstyle, glasses, beard or jewelry and a friendly expression. ';

    private const string NEVER = ' No text, no letters, no frame, no watermark.';

    public function label(): string
    {
        return match ($this) {
            self::Line => 'Feine Linien',
            self::Watercolor => 'Aquarell',
            self::Pencil => 'Bleistift',
            self::Comic => 'Comic',
            self::PopArt => 'Pop-Art',
        };
    }

    public function prompt(?PortraitGadget $gadget = null): string
    {
        $accessory = null === $gadget
            ? ''
            : ' Add one playful accessory, drawn in exactly the same style as the portrait: the person is '
            . $gadget->prompt()
            . '.';

        return (
            self::SUBJECT . match ($this) {
                self::Line
                    => 'Fine single-weight black ink line drawing on a pure white background, hand-drawn, elegant wedding stationery style, '
                . 'few confident continuous lines. No shading, no hatching, no fill, no color, generous white space.',
                self::Watercolor
                    => 'Soft, loose watercolor painting with delicate pencil outlines, muted sage green, ivory and blush tones, '
                . 'visible paper texture, plain off-white background, airy and elegant like wedding stationery.',
                self::Pencil
                    => 'Graphite pencil sketch with fine hatching and soft shading, light paper texture, plain white background, artistic and warm.',
                self::Comic
                    => 'Friendly flat cartoon illustration with clean outlines and soft muted colors (sage green, ivory, warm beige), '
                . 'simple shapes, plain light background.',
                self::PopArt
                    => 'Playful pop-art portrait with bold outlines, halftone dots and a limited palette of sage green, blush pink and cream, '
                . 'plain background.',
            }
            . $accessory
            . self::NEVER
        );
    }

    /** Wird als Maske in der Akzentfarbe gezeigt (sonst als farbiges Bild). */
    public function isLineArt(): bool
    {
        return self::Line === $this;
    }
}
