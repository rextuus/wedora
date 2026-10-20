<?php

declare(strict_types=1);

namespace App\Tests\Functional\App;

use App\Challenge\ChallengeTemplates;
use App\Challenge\DrinkLock;
use App\Challenge\Entity\Challenge;
use App\Guest\Entity\Guest;
use App\Media\Access\MediaAccessChecker;
use App\Media\Entity\Media;
use App\Media\MediaType;
use App\Media\Visibility;
use App\Tests\Functional\WebTestCase;
use App\Wedding\Entity\Wedding;
use DateTimeImmutable;

final class DrinkComparisonTest extends WebTestCase
{
    public function testOthersPairsAreVisibleOnlyOnceTheGuestHasBothVideos(): void
    {
        $wedding = $this->fixtures->wedding();
        $first = $this->challenge($wedding, 'first-drink');
        $last = $this->challenge($wedding, 'last-drink');
        [$me, $secret] = $this->fixtures->guest(self::fresh($wedding), 'Ich');
        [$ben] = $this->fixtures->guest(self::fresh($wedding), 'Ben');
        [$lea] = $this->fixtures->guest(self::fresh($wedding), 'Lea');
        [$mia] = $this->fixtures->guest(self::fresh($wedding), 'Mia');
        $this->video($ben, $first);
        $this->video($ben, $last);
        $this->video($lea, $first);
        $this->video($mia, $first);
        $this->video($mia, $last, Visibility::UploaderOnly);
        $foreign = $this->fixtures->wedding();
        $foreignFirst = $this->challenge($foreign, 'first-drink');
        $foreignLast = $this->challenge($foreign, 'last-drink');
        [$stranger] = $this->fixtures->guest(self::fresh($foreign), 'Fremd');
        $this->video($stranger, $foreignFirst);
        $this->video($stranger, $foreignLast);

        $this->actAsGuest($me, $secret);
        $url = "/h/{$wedding->getSlug()}/aufgaben/vorher-nachher";

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Noch gesperrt');
        self::assertSelectorCount(1, '.drink-pair');
        self::assertSelectorCount(0, '.drink-clip video');
        self::assertSelectorTextNotContains('main', 'Ben');

        $this->video($me, $first);
        $this->client->request('GET', $url);
        self::assertSelectorTextContains('main', 'Noch gesperrt');
        self::assertSelectorTextNotContains('main', 'Ben');

        // Eigenes Last-Drink-Video darf privat sein – es zählt trotzdem.
        $this->video($me, $last, Visibility::UploaderOnly);
        $this->client->request('GET', $url);
        self::assertSelectorTextNotContains('main', 'Noch gesperrt');
        self::assertSelectorCount(2, '.drink-pair');
        self::assertSelectorTextContains('.drink-pair:not(.drink-pair--own)', 'Ben');
        self::assertSelectorTextNotContains('main', 'Lea', 'Nur ein Video: kein Paar.');
        self::assertSelectorTextNotContains('main', 'Mia', 'Privates Video wird anderen nicht gezeigt.');
        self::assertSelectorTextNotContains('main', 'Fremd');
        self::assertSelectorCount(4, '.drink-clip video');

        // Kein eigener Punkt in der Aufgabenliste – verlinkt ist es in den beiden Drink-Aufgaben.
        $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben");
        self::assertSelectorTextNotContains('main', 'Vorher – nachher');
        $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben/{$first->getId()->toRfc4122()}");
        self::assertSelectorExists('a[href="' . $url . '"]');
        self::assertSelectorExists('.drink-example video', 'Beispielvideo beim First Drink …');
        $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben/{$last->getId()->toRfc4122()}");
        self::assertSelectorExists('.drink-explainer');
        self::assertSelectorNotExists('.drink-example', '… nicht beim Last Drink.');
    }

    public function testChallengePageShowsTheOwnDrinkVideoEvenWhenPrivate(): void
    {
        $wedding = $this->fixtures->wedding();
        $first = $this->challenge($wedding, 'first-drink');
        $last = $this->challenge($wedding, 'last-drink');
        [$me, $secret] = $this->fixtures->guest(self::fresh($wedding), 'Ich');
        [$ben] = $this->fixtures->guest(self::fresh($wedding), 'Ben');
        $mine = $this->video($me, $last, Visibility::UploaderOnly);
        $this->video($me, $first);
        $bens = $this->video($ben, $last);
        $this->actAsGuest($me, $secret);

        $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben/{$last->getId()->toRfc4122()}");
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#mine-title', 'Dein Beitrag');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertLessThan(
            strpos($html, 'drink-explainer'),
            strpos($html, 'id="mine-title"'),
            'Der eigene Beitrag steht ganz oben.',
        );
        // Box verlinkt die andere Drink-Aufgabe, nicht die eigene Seite.
        $firstUrl = "/h/{$wedding->getSlug()}/aufgaben/{$first->getId()->toRfc4122()}";
        self::assertSelectorExists('.drink-explainer a.drink-explainer__step[href="' . $firstUrl . '"]');
        self::assertSelectorCount(1, '.drink-explainer a');
        self::assertSelectorCount(1, '[aria-labelledby="mine-title"] .tile');
        self::assertSelectorExists('[aria-labelledby="mine-title"] a[href$="' . $mine->getId()->toRfc4122() . '"]');
        self::assertSelectorTextContains('[aria-labelledby="mine-title"]', 'Privat');
        self::assertSelectorTextContains('main', 'Beiträge der anderen');
        self::assertSelectorExists('a[href$="' . $bens->getId()->toRfc4122() . '"]');
        self::assertSelectorCount(
            1,
            'a[href$="' . $mine->getId()->toRfc4122() . '"]',
            'Das eigene Video steht nicht doppelt.',
        );

        // Ohne eigenen Beitrag gibt es den Bereich nicht.
        $other = $this->challenge($wedding, 'first-dance');
        $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben/{$other->getId()->toRfc4122()}");
        self::assertSelectorNotExists('#mine-title');
    }

    public function testOtherGuestsDrinkVideosStayBlurredUntilTheGuestFilmedTheSameChallenge(): void
    {
        $wedding = $this->fixtures->wedding();
        $first = $this->challenge($wedding, 'first-drink');
        $last = $this->challenge($wedding, 'last-drink');
        [$me, $secret] = $this->fixtures->guest(self::fresh($wedding), 'Ich');
        [$ben] = $this->fixtures->guest(self::fresh($wedding), 'Ben');
        $benFirst = $this->video($ben, $first);
        $benLast = $this->video($ben, $last);
        $photo = $this->fixtures->guestMedia(self::fresh($ben));
        $this->actAsGuest($me, $secret);
        $checker = self::service(MediaAccessChecker::class);
        $subject = 'guest:' . $me->getId()->toRfc4122();
        $id = $benFirst->getId()->toRfc4122();

        $this->client->request('GET', "/h/{$wedding->getSlug()}/galerie");
        self::assertSelectorCount(2, '.tile--locked');
        self::assertSelectorTextContains('.tile--locked', 'Erst eigenes');
        self::assertSelectorNotExists('.tile--locked[data-video], .tile--locked[data-src], .tile--locked img');
        self::assertSelectorCount(1, '.tile:not(.tile--locked)[data-src]', 'Normale Fotos bleiben sichtbar.');
        self::assertFalse($checker->canView($subject, $id, 'video.mp4'));
        self::assertFalse($checker->canView($subject, $id, 'image/thumb'));
        self::assertFalse($checker->canView($subject, $id, 'image/display'));
        self::assertTrue($checker->canView($subject, $id, 'image/tiny'), 'Nur die verwischte Mini-Vorschau.');
        self::assertTrue($checker->canView($subject, $photo->getId()->toRfc4122(), 'image/display'));
        $this->client->request('GET', "/h/{$wedding->getSlug()}/m/{$id}");
        self::assertResponseStatusCodeSame(404);

        // Eigene Videos sind nie gesperrt; das eigene First Drink schaltet alle First Drinks frei …
        $mine = $this->video($me, $first, Visibility::UploaderOnly);
        self::assertTrue($checker->canView($subject, $mine->getId()->toRfc4122(), 'video.mp4'));
        self::service(DrinkLock::class)->reset();
        self::assertTrue($checker->canView($subject, $id, 'video.mp4'));
        self::assertFalse($checker->canView($subject, $benLast->getId()->toRfc4122(), 'video.mp4'));
        $this->client->request('GET', "/h/{$wedding->getSlug()}/galerie");
        self::assertSelectorCount(1, '.tile--locked');
        self::assertSelectorTextContains('.tile--locked', 'Erst eigenes Last Drink');
        $this->client->request('GET', "/h/{$wedding->getSlug()}/m/{$id}");
        self::assertResponseIsSuccessful();
        $this->client->request('GET', "/h/{$wedding->getSlug()}/m/{$benLast->getId()->toRfc4122()}");
        self::assertResponseStatusCodeSame(404);

        // … das eigene Last Drink alle Last Drinks.
        $this->video($me, $last);
        self::service(DrinkLock::class)->reset();
        $checker = self::service(MediaAccessChecker::class);
        self::assertTrue($checker->canView($subject, $benLast->getId()->toRfc4122(), 'video.mp4'));
        $this->client->request('GET', "/h/{$wedding->getSlug()}/galerie");
        self::assertSelectorNotExists('.tile--locked');
    }

    public function testLockedPageShowsWhenTheLastDrinkOpens(): void
    {
        $wedding = $this->fixtures->wedding();
        $this->challenge($wedding, 'first-drink');
        $last = $this->challenge($wedding, 'last-drink');
        self::fresh($last)->schedule(new DateTimeImmutable('+3 hours'), null);
        self::em()->flush();
        [$me, $secret] = $this->fixtures->guest(self::fresh($wedding));
        $this->actAsGuest($me, $secret);

        $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben/vorher-nachher");
        self::assertResponseIsSuccessful();
        $startsAt = self::fresh($last)->getStartsAt();
        self::assertNotNull($startsAt);
        self::assertSelectorTextContains('main', 'Last Drink (ab ' . $startsAt->format('H:i') . ' Uhr)');
    }

    public function testWithoutBothChallengesThereIsNoComparison(): void
    {
        $wedding = $this->fixtures->wedding();
        $this->challenge($wedding, 'first-drink');
        [$me, $secret] = $this->fixtures->guest(self::fresh($wedding));
        $this->actAsGuest($me, $secret);

        $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben/vorher-nachher");
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben");
        self::assertSelectorTextNotContains('main', 'Vorher – nachher');
    }

    private function challenge(Wedding $wedding, string $template): Challenge
    {
        $t = ChallengeTemplates::all()[$template];
        $challenge = new Challenge(self::fresh($wedding), $t['title']);
        $challenge->setMediaType($t['type']);
        self::em()->persist($challenge);
        self::em()->flush();

        return $challenge;
    }

    private function video(Guest $guest, Challenge $challenge, Visibility $visibility = Visibility::AllGuests): Media
    {
        $media = $this->fixtures->guestMedia(self::fresh($guest), $visibility, MediaType::Video);
        self::fresh($media)->assignChallenge(self::fresh($challenge));
        self::em()->flush();

        return $media;
    }
}
