<?php

declare(strict_types=1);

namespace App\Guest\Portrait;

/**
 * Accessoire, das ins Porträt mitgezeichnet wird – im selben Stil wie das Porträt. Welches, bestimmt die
 * Antwort auf eine zufällige Frage beim Selfie (PortraitQuestions).
 */
enum PortraitGadget: string
{
    case Sunglasses = 'sunglasses';
    case PartyHat = 'party_hat';
    case FlowerCrown = 'flower_crown';
    case BowTie = 'bow_tie';
    case TopHat = 'top_hat';
    case HeartGlasses = 'heart_glasses';
    case Crown = 'crown';
    case Confetti = 'confetti';

    public function label(): string
    {
        return match ($this) {
            self::Sunglasses => 'mit Sonnenbrille',
            self::PartyHat => 'mit Partyhut',
            self::FlowerCrown => 'mit Blumenkranz',
            self::BowTie => 'mit Fliege',
            self::TopHat => 'mit Zylinder',
            self::HeartGlasses => 'mit Herzbrille',
            self::Crown => 'mit Krone',
            self::Confetti => 'im Konfettiregen',
        };
    }

    public function prompt(): string
    {
        return match ($this) {
            self::Sunglasses => 'wearing cool oversized sunglasses',
            self::PartyHat => 'wearing a pointed party hat with a small pompom, slightly tilted',
            self::FlowerCrown => 'wearing a delicate flower crown',
            self::BowTie => 'wearing a neat bow tie',
            self::TopHat => 'wearing a small elegant top hat, slightly tilted',
            self::HeartGlasses => 'wearing playful heart-shaped glasses',
            self::Crown => 'wearing a small playful crown',
            self::Confetti => 'with a few pieces of confetti falling around the head',
        };
    }
}
