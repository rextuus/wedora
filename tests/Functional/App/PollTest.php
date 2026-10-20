<?php

declare(strict_types=1);

namespace App\Tests\Functional\App;

use App\Account\WeddingPermission;
use App\Challenge\ChallengeMediaType;
use App\Challenge\Entity\Challenge;
use App\Poll\CouplePoll;
use App\Poll\Entity\PollQuestion;
use App\Poll\Entity\PollVote;
use App\Poll\PollChoice;
use App\Poll\Repository\PollQuestionRepository;
use App\Tests\Functional\WebTestCase;
use App\Wedding\Entity\Wedding;
use DateTimeImmutable;

final class PollTest extends WebTestCase
{
    public function testGuestsVoteChangeTheirMindAndSeeTheResult(): void
    {
        $wedding = $this->game(['Wer tanzt heute mehr?', 'Wer kocht besser?']);
        [$question] = self::service(PollQuestionRepository::class)->forWedding(self::fresh($wedding));
        [$other] = $this->fixtures->guest(self::fresh($wedding), 'Marie');
        self::em()->persist(new PollVote(self::fresh($question), self::fresh($other), PollChoice::Second));
        self::em()->flush();
        [$guest, $secret] = $this->fixtures->guest(self::fresh($wedding));
        $this->actAsGuest($guest, $secret);

        $crawler = $this->client->request('GET', "/h/{$wedding->getSlug()}/wer-von-beiden");
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Wer von beiden');
        self::assertSelectorTextContains('.poll-q', 'Wer tanzt heute mehr?');
        self::assertSelectorTextContains('.poll-choice', 'Larissa');
        self::assertSelectorNotExists('.poll-bar', 'Vor der eigenen Stimme kein Ergebnis.');

        $form = $crawler->filter('.poll-q')->first()->selectButton('Larissa')->form();
        $this->client->submit($form);
        self::assertResponseRedirects(
            "/h/{$wedding->getSlug()}/wer-von-beiden#frage-{$question->getId()->toRfc4122()}",
        );
        $this->client->followRedirect();
        self::assertSelectorTextContains('.poll-choice.is-mine', 'Larissa');
        self::assertSelectorTextContains('.poll-bar__legend', 'Larissa · 50 %');

        // Umentscheiden ändert die Stimme, statt eine zweite anzulegen.
        $crawler = $this->client->request('GET', "/h/{$wedding->getSlug()}/wer-von-beiden");
        $this->client->submit($crawler->filter('.poll-q')->first()->selectButton('Tim')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.poll-choice.is-mine', 'Tim');
        self::assertSame(2, self::em()
            ->getRepository(PollVote::class)
            ->count(['question' => self::fresh($question)]));

        // In der Aufgabenliste sieht die Umfrage aus wie jede Aufgabe, mit Status und Fortschritt.
        $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben");
        self::assertSelectorTextContains('.challenge-item:not(.is-done)', 'Wer von beiden?');
        self::assertSelectorTextContains('.challenge-item .status--pending', 'Offen');
        self::assertSelectorTextContains('.challenge-item', 'Umfrage · 1 von 2 Fragen beantwortet');
        self::assertSelectorTextNotContains(
            '.challenge-item',
            'mitgemacht',
            'Mitgemacht hat erst, wer alle Fragen beantwortet.',
        );
        $crawler = $this->client->request('GET', "/h/{$wedding->getSlug()}/wer-von-beiden");
        $this->client->submit($crawler->filter('.poll-q')->eq(1)->selectButton('Tim')->form());
        $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben");
        self::assertSelectorTextContains('.challenge-item.is-done .status--done', 'Erledigt');
        self::assertSelectorTextContains('.challenge-item', '1 Gast hat mitgemacht');
        self::assertSelectorTextContains('.section-header', '1 von 1 erledigt');
        $this->client->request('GET', "/h/{$wedding->getSlug()}/wer-von-beiden");
        self::assertSelectorTextContains('.section-header .status--done', 'Erledigt');
    }

    public function testPollIsATaskWithPointsTimeWindowAndNoUpload(): void
    {
        $wedding = $this->game(['Wer tanzt heute mehr?']);
        self::fresh($wedding)->enableChallengePoints(true);
        $challenge = self::service(CouplePoll::class)->challenge(self::fresh($wedding));
        self::assertNotNull($challenge);
        self::fresh($challenge)->schedule(new DateTimeImmutable('+2 hours'), null);
        self::em()->flush();
        [$guest, $secret] = $this->fixtures->guest(self::fresh($wedding), 'Sophie');
        $this->actAsGuest($guest, $secret);
        $id = $challenge->getId()->toRfc4122();

        // Aufgabenseite und Upload führen zur Umfrage; hochladen lässt sich nichts.
        $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben/{$id}");
        self::assertResponseRedirects("/h/{$wedding->getSlug()}/wer-von-beiden");
        $this->client->request('GET', "/h/{$wedding->getSlug()}/upload?aufgabe={$id}");
        self::assertResponseRedirects("/h/{$wedding->getSlug()}/wer-von-beiden");

        // Vor dem Zeitfenster: ansehen ja, abstimmen nein.
        $crawler = $this->client->request('GET', "/h/{$wedding->getSlug()}/wer-von-beiden");
        self::assertSelectorTextContains('.notice', 'Abstimmen kannst du ab');
        self::assertSelectorExists('.poll-choice[disabled]');
        $token = (string) $crawler->filter('.poll-q input[name="_token"]')->attr('value');
        $question = self::service(PollQuestionRepository::class)->forWedding(self::fresh($wedding))[0];
        $this->client->request('POST', "/h/{$wedding->getSlug()}/wer-von-beiden/{$question->getId()->toRfc4122()}", [
            '_token' => $token,
            'choice' => 'first',
        ]);
        self::assertSame(0, self::em()->getRepository(PollVote::class)->count([]));

        // Im Zeitfenster: alle Fragen beantwortet → erledigt, Punkte in der Rangliste.
        self::fresh($challenge)->schedule(null, null);
        self::em()->flush();
        $crawler = $this->client->request('GET', "/h/{$wedding->getSlug()}/wer-von-beiden");
        $this->client->submit($crawler->filter('.poll-q')->first()->selectButton('Larissa')->form());
        $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben");
        self::assertSelectorTextContains('.challenge-item.is-done', 'Wer von beiden?');
        self::assertSelectorTextContains('.ranking', 'Sophie');
        self::assertSelectorTextContains('.ranking', '10');
    }

    public function testHiddenResultsShowOnlyTheOwnVote(): void
    {
        $wedding = $this->game(['Wer tanzt heute mehr?']);
        self::fresh($wedding)->showPollLiveResults(false);
        [$question] = self::service(PollQuestionRepository::class)->forWedding(self::fresh($wedding));
        [$guest, $secret] = $this->fixtures->guest(self::fresh($wedding));
        self::em()->persist(new PollVote(self::fresh($question), self::fresh($guest), PollChoice::First));
        self::em()->flush();
        $this->actAsGuest($guest, $secret);

        $this->client->request('GET', "/h/{$wedding->getSlug()}/wer-von-beiden");
        self::assertSelectorNotExists('.poll-bar');
        self::assertSelectorTextContains('.poll-q', 'Deine Stimme: Larissa');
    }

    public function testServerChecksTokenChoiceWeddingAndSwitch(): void
    {
        $wedding = $this->game(['Wer tanzt heute mehr?']);
        $foreign = $this->game(['Wer kocht besser?']);
        [$question] = self::service(PollQuestionRepository::class)->forWedding(self::fresh($wedding));
        [$foreignQuestion] = self::service(PollQuestionRepository::class)->forWedding(self::fresh($foreign));
        [$guest, $secret] = $this->fixtures->guest(self::fresh($wedding));
        $this->actAsGuest($guest, $secret);
        $crawler = $this->client->request('GET', "/h/{$wedding->getSlug()}/wer-von-beiden");
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $url = "/h/{$wedding->getSlug()}/wer-von-beiden/";

        // @mago-expect lint:no-literal-password
        $this->client->request('POST', $url . $question->getId()->toRfc4122(), [
            '_token' => 'falsch',
            'choice' => 'first',
        ]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $url . $question->getId()->toRfc4122(), [
            '_token' => $token,
            'choice' => 'beide',
        ]);
        self::assertResponseStatusCodeSame(400);
        $this->client->request('POST', $url . $foreignQuestion->getId()->toRfc4122(), [
            '_token' => $token,
            'choice' => 'first',
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, self::em()->getRepository(PollVote::class)->count([]));

        self::service(CouplePoll::class)->challenge(self::fresh($wedding))?->setEnabled(false);
        self::em()->flush();
        $this->client->request('GET', "/h/{$wedding->getSlug()}/wer-von-beiden");
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', "/h/{$wedding->getSlug()}/aufgaben");
        self::assertSelectorTextNotContains('main', 'Wer von beiden');
    }

    public function testCoupleManagesQuestionsInTheAdmin(): void
    {
        $wedding = $this->fixtures->wedding(slug: 'schuh-' . bin2hex(random_bytes(3)));
        self::fresh($wedding)->rename('Larissa & Tim');
        self::em()->flush();
        $user = $this->fixtures->user();
        $this->fixtures->member($user, self::fresh($wedding), [WeddingPermission::Configure]);
        $this->client->loginUser(self::fresh($user));
        $base = "/admin/h/{$wedding->getSlug()}/umfrage";

        $crawler = $this->client->request('GET', $base);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.notice', 'noch keine Aufgabe');
        $this->client->submit($crawler->selectButton('+ Wer tanzt heute mehr?')->form());
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Hinzufügen')->form(['text' => 'Wer tanzt schöner?']));
        $crawler = $this->client->followRedirect();
        // Die Umfrage wird eine normale Aufgabe – direkt von hier oder unter „Aufgaben“.
        $this->client->submit($crawler->selectButton('Als Aufgabe anlegen')->form());
        $this->client->followRedirect();
        $crawler = $this->client->request('GET', "/admin/h/{$wedding->getSlug()}/aufgaben");
        self::assertSelectorTextContains('details', 'Wer von beiden?');
        self::assertSelectorExists('details a[href="' . $base . '"]');
        self::assertSelectorTextNotContains('.chip', 'Wer von beiden?', 'Vorschlag schon übernommen.');

        $questions = self::service(PollQuestionRepository::class)->forWedding(self::fresh($wedding));
        self::assertSame(
            ['Wer tanzt heute mehr?', 'Wer tanzt schöner?'],
            array_map(static fn(PollQuestion $q): string => $q->getText(), $questions),
        );
        $challenge = self::service(CouplePoll::class)->challenge(self::fresh($wedding));
        self::assertNotNull($challenge);
        self::assertTrue($challenge->isEnabled());
        self::assertTrue(self::service(CouplePoll::class)->isVisibleIn(self::fresh($wedding)));

        // Nur eine Umfrage pro Hochzeit.
        $this->client->submit($crawler
            ->filter('form')
            ->reduce(static fn($f): bool => str_contains($f->text(), 'Anlegen'))
            ->form(['title' => 'Noch eine Umfrage', 'type' => 'poll']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash, .notice, [role="alert"], main', 'Es gibt schon eine Umfrage');

        $crawler = $this->client->request('GET', $base);
        $this->client->submit(
            $crawler
                ->filter('#frage-' . $questions[1]->getId()->toRfc4122())
                ->selectButton('Löschen')
                ->form(),
        );
        self::assertCount(1, self::service(PollQuestionRepository::class)->forWedding(self::fresh($wedding)));

        // Nur mit Recht „Einstellungen“.
        $moderator = $this->fixtures->user();
        $this->fixtures->member($moderator, self::fresh($wedding), [WeddingPermission::Moderate]);
        $this->client->loginUser(self::fresh($moderator));
        $this->client->request('GET', $base);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param list<string> $questions
     */
    private function game(array $questions): Wedding
    {
        $wedding = $this->fixtures->wedding(slug: 'schuh-' . bin2hex(random_bytes(3)));
        $wedding = self::fresh($wedding);
        $wedding->rename('Larissa & Tim');
        $challenge = new Challenge($wedding, 'Wer von beiden?');
        $challenge->setMediaType(ChallengeMediaType::Poll);
        $challenge->setPoints(10);
        self::em()->persist($challenge);
        foreach ($questions as $i => $text) {
            $question = new PollQuestion($wedding, $text);
            $question->moveTo($i);
            self::em()->persist($question);
        }

        self::em()->flush();

        return $wedding;
    }
}
