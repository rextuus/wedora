<?php

declare(strict_types=1);

namespace App\Guest\Portrait;

use App\Wedding\CoupleNames;

/**
 * Kurze Fragen beim Selfie: Die Antwort bestimmt das Accessoire im Porträt. Den Gästen wird das bewusst
 * nicht verraten – für sie ist das Accessoire eine Überraschung.
 * Pro Porträt wird zufällig eine Frage gestellt.
 */
final class PortraitQuestions
{
    /**
     * @return array<string, array{question: string, answers: array<string, array{label: string, gadget: PortraitGadget}>}>
     */
    public static function all(CoupleNames $names): array
    {
        return [
            'origin' => [
                'question' => 'Woher kennst du das Brautpaar?',
                'answers' => [
                    'family' => ['label' => 'Familie', 'gadget' => PortraitGadget::Crown],
                    'school' => ['label' => 'Schule oder Studium', 'gadget' => PortraitGadget::BowTie],
                    'work' => ['label' => 'Arbeit', 'gadget' => PortraitGadget::TopHat],
                    'friends' => ['label' => 'Freundeskreis oder Verein', 'gadget' => PortraitGadget::HeartBoppers],
                ],
            ],
            'wish' => [
                'question' => 'Was wünschst du den beiden?',
                'answers' => [
                    'travel' => ['label' => 'Viele Reisen', 'gadget' => PortraitGadget::Butterfly],
                    'love' => ['label' => 'Ganz viel Liebe', 'gadget' => PortraitGadget::Dove],
                    'party' => ['label' => 'Immer einen Grund zum Feiern', 'gadget' => PortraitGadget::PartyHat],
                    'home' => ['label' => 'Ein Zuhause voller Blumen', 'gadget' => PortraitGadget::FlowerCrown],
                ],
            ],
            'tonight' => [
                'question' => 'Was machst du heute am liebsten?',
                'answers' => [
                    'dance' => ['label' => 'Tanzen', 'gadget' => PortraitGadget::FeatherBoa],
                    'food' => ['label' => 'Essen und Trinken', 'gadget' => PortraitGadget::EarFlower],
                    'talk' => ['label' => 'Quatschen', 'gadget' => PortraitGadget::Bubbles],
                    'late' => ['label' => 'Feiern bis zum Schluss', 'gadget' => PortraitGadget::Confetti],
                ],
            ],
            'closer' => [
                'question' => 'Wen kennst du länger?',
                'answers' => [
                    'first' => ['label' => $names->first, 'gadget' => PortraitGadget::FlowerCrown],
                    'second' => ['label' => $names->second, 'gadget' => PortraitGadget::TopHat],
                    'both' => ['label' => 'Beide gleich lang', 'gadget' => PortraitGadget::HeartTattoo],
                ],
            ],
            'since' => [
                'question' => 'Wie lange kennst du die beiden schon?',
                'answers' => [
                    'today' => ['label' => 'Seit heute', 'gadget' => PortraitGadget::Bubbles],
                    'years' => ['label' => 'Ein paar Jahre', 'gadget' => PortraitGadget::HeartBoppers],
                    'forever' => ['label' => 'Gefühlt schon ewig', 'gadget' => PortraitGadget::Crown],
                ],
            ],
        ];
    }

    /** Zufällige Frage (Schlüssel). */
    public static function randomKey(CoupleNames $names): string
    {
        $keys = array_keys(self::all($names));
        shuffle($keys);

        return $keys[0] ?? 'origin';
    }

    /** Accessoire zur Antwort – null, wenn Frage oder Antwort unbekannt sind. */
    public static function gadgetFor(CoupleNames $names, string $question, string $answer): ?PortraitGadget
    {
        return self::all($names)[$question]['answers'][$answer]['gadget'] ?? null;
    }
}
