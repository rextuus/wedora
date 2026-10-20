<?php

declare(strict_types=1);

namespace App\Tests\Unit\Guest;

use App\Guest\Portrait\PortraitGadget;
use App\Guest\Portrait\PortraitQuestions;
use App\Guest\Portrait\PortraitStyle;
use App\Wedding\CoupleNames;
use PHPUnit\Framework\TestCase;

/** Herz-Tattoo mit den Initialen des Paares: nur dafür sind Buchstaben im Bild erlaubt. */
final class PortraitPromptTest extends TestCase
{
    public function testHeartTattooCarriesTheCouplesInitials(): void
    {
        $names = CoupleNames::fromTitle('Larissa & Tim');
        self::assertSame('L & T', $names->initials());

        $prompt = PortraitStyle::Line->prompt(PortraitGadget::HeartTattoo, $names);
        self::assertStringContainsString('heart tattoo on one cheek', $prompt);
        self::assertStringContainsString('"L & T"', $prompt);
        self::assertStringContainsString('No other text or letters apart from the initials', $prompt);
        self::assertStringNotContainsString('No text, no letters', $prompt);
    }

    public function testOtherAccessoriesStillForbidAnyText(): void
    {
        $names = CoupleNames::fromTitle('Larissa & Tim');
        self::assertStringContainsString('No text, no letters', PortraitStyle::Line->prompt(
            PortraitGadget::Crown,
            $names,
        ));
        self::assertStringContainsString('No text, no letters', PortraitStyle::Line->prompt(null, $names));
    }

    public function testWithoutTwoNamesTheHeartStaysEmpty(): void
    {
        $names = CoupleNames::fromTitle('Unsere Hochzeit');
        self::assertNull($names->initials());
        $prompt = PortraitStyle::Comic->prompt(PortraitGadget::HeartTattoo, $names);
        self::assertStringContainsString('heart tattoo', $prompt);
        self::assertStringContainsString('No text, no letters', $prompt);
    }

    public function testItIsTheSurpriseForKnowingBothEquallyLong(): void
    {
        $names = CoupleNames::fromTitle('Larissa & Tim');
        self::assertSame(PortraitGadget::HeartTattoo, PortraitQuestions::gadgetFor($names, 'closer', 'both'));
        self::assertSame('mit Herz-Tattoo', PortraitGadget::HeartTattoo->label());
    }
}
