<?php

declare(strict_types=1);

namespace App\Tests\Functional\App;

use App\Account\WeddingPermission;
use App\Tests\Functional\WebTestCase;
use App\Wedding\Entity\Wedding;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use RuntimeException;
use Symfony\Component\HttpKernel\Profiler\Profile;

/**
 * Seiten dürfen nicht je Beitrag oder je Rechteprüfung erneut die Datenbank fragen (N+1):
 * Am Hochzeitstag hat die Galerie Hunderte Beiträge von vielen Gästen.
 */
final class QueryCountTest extends WebTestCase
{
    public function testGalleryQueriesDoNotGrowWithTheNumberOfContributors(): void
    {
        $wedding = $this->fixtures->wedding();
        [$viewer, $secret] = $this->fixtures->guest($wedding, 'Betrachter');
        $this->contributions($wedding, 2);
        $this->actAsGuest($viewer, $secret);
        // Erster Aufruf nutzt noch die beim Anlegen geladenen Entities; verglichen werden zwei echte Aufrufe.
        $this->selects("/h/{$wedding->getSlug()}/galerie");
        $few = count($this->selects("/h/{$wedding->getSlug()}/galerie"));

        $this->contributions(self::fresh($wedding), 10);
        $this->actAsGuest($viewer, $secret);
        $many = count($this->selects("/h/{$wedding->getSlug()}/galerie"));

        self::assertSame($few, $many, 'Gleich viele Abfragen bei 2 und 12 Beiträgen von verschiedenen Gästen.');
    }

    public function testAdminPagesLoadPermissionsOncePerRequest(): void
    {
        $wedding = $this->fixtures->wedding();
        $user = $this->fixtures->user();
        $this->fixtures->member($user, $wedding, WeddingPermission::cases());
        $this->client->loginUser(self::fresh($user));

        $permissionLookups = array_filter(
            $this->selects("/admin/h/{$wedding->getSlug()}/einstellungen"),
            static fn(string $query): bool => str_contains($query, 'FROM wedding_member'),
        );

        self::assertCount(1, $permissionLookups, 'Jede Rechteprüfung der Seite nutzt dieselben geladenen Rechte.');
    }

    private function contributions(Wedding $wedding, int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            [$guest] = $this->fixtures->guest($wedding, "Gast {$i}");
            $this->fixtures->guestMedia($guest);
        }
    }

    /**
     * Lesende Abfragen der Seite (das Anlegen der Testdaten läuft über dieselbe Verbindung und zählt nicht).
     *
     * @return list<string>
     */
    private function selects(string $url): array
    {
        $this->client->enableProfiler();
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $profile = $this->client->getProfile();
        $collector = $profile instanceof Profile ? $profile->getCollector('db') : null;
        if (!$collector instanceof DoctrineDataCollector) {
            throw new RuntimeException('Doctrine-Profiler fehlt.');
        }

        /** @var array<string, list<array{sql: string}>> $queries */
        $queries = $collector->getQueries();
        $sql = array_column($queries['default'] ?? [], 'sql');

        return array_values(array_filter($sql, static fn(string $query): bool => str_starts_with($query, 'SELECT')));
    }
}
