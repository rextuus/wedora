<?php

declare(strict_types=1);

namespace App\Guest\Portrait;

use App\Wedding\CoupleNames;

/**
 * Accessoire, das ins Porträt mitgezeichnet wird – im selben Stil wie das Porträt. Welches, bestimmt die
 * Antwort auf eine zufällige Frage beim Selfie (PortraitQuestions).
 *
 * Sonnenbrille und Herzbrille werden nicht mehr vergeben (wer schon eine Brille trägt, bekäme eine zweite);
 * sie bleiben nur für bereits gezeichnete Porträts erhalten.
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
    case HeartTattoo = 'heart_tattoo';
    case HeartBoppers = 'heart_boppers';
    case FeatherBoa = 'feather_boa';
    case Butterfly = 'butterfly';
    case Bubbles = 'bubbles';
    case EarFlower = 'ear_flower';
    case Dove = 'dove';

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
            self::HeartTattoo => 'mit Herz-Tattoo',
            self::HeartBoppers => 'mit Herzchen-Haarreif',
            self::FeatherBoa => 'mit Federboa',
            self::Butterfly => 'mit Schmetterling',
            self::Bubbles => 'mit Seifenblasen',
            self::EarFlower => 'mit Blüte hinterm Ohr',
            self::Dove => 'mit Taube auf der Schulter',
        };
    }

    /** Mit den Namen des Paares, z. B. für die Initialen im Herz-Tattoo. */
    public function prompt(?CoupleNames $names = null): string
    {
        $initials = $names?->initials();

        return match ($this) {
            self::Sunglasses => 'wearing cool oversized sunglasses',
            self::PartyHat => 'wearing a pointed party hat with a small pompom, slightly tilted',
            self::FlowerCrown => 'wearing a delicate flower crown',
            self::BowTie => 'wearing a neat bow tie',
            self::TopHat => 'wearing a small elegant top hat, slightly tilted',
            self::HeartGlasses => 'wearing playful heart-shaped glasses',
            self::Crown => 'wearing a small playful crown',
            self::Confetti => 'with a few pieces of confetti falling around the head',
            self::HeartTattoo => null === $initials
                ? 'with a small cute heart tattoo on one cheek'
                : sprintf(
                    'with a small cute heart tattoo on one cheek, with the initials "%s" written inside the heart',
                    $initials,
                ),
            self::HeartBoppers => 'wearing a playful headband with two little hearts bobbing on springs',
            self::FeatherBoa => 'wearing a fluffy feather boa around the neck',
            self::Butterfly => 'with a small butterfly resting on one shoulder',
            self::Bubbles => 'with a few soap bubbles floating around the head',
            self::EarFlower => 'with a single flower tucked behind one ear',
            self::Dove => 'with a small white dove sitting on one shoulder',
        };
    }

    /** Darf Buchstaben enthalten (sonst verbietet der Prompt jeden Text). */
    public function hasLetters(?CoupleNames $names): bool
    {
        return self::HeartTattoo === $this && null !== $names?->initials();
    }
}
