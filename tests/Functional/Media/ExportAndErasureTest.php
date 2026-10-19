<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Account\Entity\User;
use App\Account\WeddingPermission;
use App\Guest\Entity\Guest;
use App\Media\Entity\Export;
use App\Media\Entity\Media;
use App\Media\Entity\Upload;
use App\Media\Export\ExportFiles;
use App\Media\Export\ExportService;
use App\Media\ExportStatus;
use App\Media\MediaType;
use App\Media\Storage\MediaFile;
use App\Media\Storage\MediaStorage;
use App\Media\Upload\StorageUsage;
use App\Media\Upload\UploadBuffer;
use App\Media\Visibility;
use App\Tests\Functional\WebTestCase;
use App\Wedding\Entity\Wedding;
use App\Wedding\ModerationMode;
use App\Wedding\ModuleType;
use App\Wedding\WeddingEraser;
use DateTimeImmutable;
use Symfony\Component\Filesystem\Filesystem;
use ZipArchive;

final class ExportAndErasureTest extends WebTestCase
{
    private const string PHOTO = __DIR__ . '/../../Fixtures/phase0.jpg';

    protected function tearDown(): void
    {
        $root = $_SERVER['MEDIA_ROOT'] ?? null;
        new Filesystem()->remove(is_string($root) ? $root : '/tmp/wedora-test-media');
        parent::tearDown();
    }

    public function testExportContainsOnlyItemsForGuestsOrCoupleWithOverview(): void
    {
        $wedding = $this->fixtures->wedding(ModerationMode::Approval);
        [$sophie] = $this->fixtures->guest($wedding, 'Sophie Müller');
        $published = $this->photo($sophie, Visibility::AllGuests, publish: true);
        $pending = $this->photo($sophie, Visibility::Couple);
        $private = $this->photo($sophie, Visibility::UploaderOnly);
        $rejected = $this->photo($sophie, Visibility::AllGuests);
        self::fresh($rejected)->reject(new DateTimeImmutable());
        $deleted = $this->photo($sophie, Visibility::AllGuests, publish: true);
        self::fresh($deleted)->markDeleted(new DateTimeImmutable());
        $this->fixtures->flush();
        [$foreignGuest] = $this->fixtures->guest($this->fixtures->wedding(), 'Fremd');
        $this->photo($foreignGuest, Visibility::AllGuests, publish: true);

        $export = self::service(ExportService::class)->request(self::fresh($wedding), null);
        $export = self::fresh($export);

        self::assertSame(ExportStatus::Ready, $export->getStatus());
        self::assertSame(2, $export->getItemCount());

        $zip = new ZipArchive();
        self::assertTrue($zip->open(self::service(ExportFiles::class)->localPath($export)));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = (string) $zip->getNameIndex($i);
        }

        $shortId = static fn(Media $m): string => substr($m->getId()->toRfc4122(), -8);
        self::assertCount(3, $names, 'zwei Fotos + Übersicht');
        self::assertContains('uebersicht.csv', $names);
        foreach ([$published, $pending] as $included) {
            self::assertNotEmpty(array_filter($names, static fn(string $n): bool => str_contains(
                $n,
                $shortId($included),
            )));
        }
        foreach ([$private, $rejected, $deleted] as $excluded) {
            self::assertEmpty(array_filter($names, static fn(string $n): bool => str_contains(
                $n,
                $shortId($excluded),
            )));
        }

        $photoName = (string) current(array_filter($names, static fn(string $n): bool => str_starts_with(
            $n,
            'fotos/',
        )));
        self::assertMatchesRegularExpression(
            '#^fotos/\d{4}-\d{2}-\d{2}_\d{6}_sophie-mueller_[0-9a-f]{8}\.jpg$#',
            $photoName,
        );
        self::assertSame(file_get_contents(self::PHOTO), $zip->getFromName($photoName), 'Original unverändert');
        $csv = (string) $zip->getFromName('uebersicht.csv');
        self::assertStringContainsString('Sophie Müller', $csv);
        self::assertStringContainsString('wartet auf Freigabe', $csv);
        $zip->close();
    }

    public function testExportDownloadRequiresExportPermission(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest] = $this->fixtures->guest($wedding);
        $this->photo($guest, Visibility::AllGuests, publish: true);
        $couple = $this->login([WeddingPermission::Export], $wedding);
        $export = self::fresh(self::service(ExportService::class)->request(self::fresh($wedding), $couple));
        $url = sprintf('/admin/exports/%s/%s-export.zip', $export->getId()->toRfc4122(), $wedding->getSlug());

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame(
            '/_protected/files/' . self::service(ExportFiles::class)->relativePath($export),
            $this->client->getResponse()->headers->get('X-Accel-Redirect'),
        );

        $this->login([WeddingPermission::ViewContent, WeddingPermission::Moderate], $wedding);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $url);
        self::assertResponseRedirects('/login');
    }

    public function testExpiredExportIsNotDownloadable(): void
    {
        $wedding = $this->fixtures->wedding();
        $user = $this->login([WeddingPermission::Export], $wedding);
        $export = new Export(self::fresh($wedding), self::fresh($user), new DateTimeImmutable('-8 days'));
        self::em()->persist($export);
        self::em()->flush();

        $this->client->request('GET', sprintf('/admin/exports/%s/x-export.zip', $export->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testErasingWeddingRemovesEverything(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest] = $this->fixtures->guest($wedding);
        $photo = $this->photo($guest, Visibility::AllGuests, publish: true);
        $storage = self::service(MediaStorage::class);
        $storage->store($photo->mediaId(), MediaFile::Video, self::PHOTO);
        $storage->store($photo->mediaId(), MediaFile::Poster, self::PHOTO);

        $upload = new Upload(
            self::fresh($guest),
            ModuleType::Gallery,
            MediaType::Photo,
            Visibility::AllGuests,
            100,
            new DateTimeImmutable(),
        );
        self::em()->persist($upload);
        self::em()->flush();
        $buffer = self::service(UploadBuffer::class);
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, 'teil');
        rewind($stream);
        $buffer->append($upload, $stream, 100);

        $export = self::fresh(self::service(ExportService::class)->request(self::fresh($wedding), null));
        $exportPath = self::service(ExportFiles::class)->localPath($export);
        [$otherGuest] = $this->fixtures->guest($this->fixtures->wedding(), 'Bleibt');
        $survivor = $this->photo($otherGuest, Visibility::AllGuests, publish: true);

        self::assertFileExists($exportPath);
        self::assertFileExists($buffer->path($upload));

        self::service(WeddingEraser::class)->erase(self::fresh($wedding));

        self::assertSame([], $storage->list($photo->mediaId()), 'Original, Video, Standbild');
        self::assertFileDoesNotExist($buffer->path($upload), 'offene Upload-Stücke');
        self::assertFileDoesNotExist($exportPath, 'Export-Archive');
        $connection = self::em()->getConnection();
        $id = $wedding->getId()->toBinary();
        foreach ([
            'wedding' => 'id',
            'guest' => 'wedding_id',
            'media' => 'wedding_id',
            'media_export' => 'wedding_id',
            'wedding_module' => 'wedding_id',
        ] as $table => $column) {
            self::assertSame(
                0,
                self::rowCount($connection->fetchOne("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?", [$id])),
                $table,
            );
        }
        $uploadRows = $connection->fetchOne('SELECT COUNT(*) FROM media_upload WHERE id = ?', [$upload
            ->getId()
            ->toBinary()]);
        self::assertSame(0, self::rowCount($uploadRows));

        self::assertNotSame([], $storage->list($survivor->mediaId()), 'Andere Hochzeiten bleiben unberührt.');
    }

    public function testStorageUsageCountsOriginalsDerivedAndReservations(): void
    {
        $wedding = $this->fixtures->wedding();
        [$guest] = $this->fixtures->guest($wedding);
        $media = Media::uploadedByGuest(
            self::fresh($guest),
            ModuleType::Gallery,
            MediaType::Video,
            Visibility::AllGuests,
        );
        $media->recordOriginal('video/mp4', 1000);
        $media->markReady(250);
        $gone = Media::uploadedByGuest(
            self::fresh($guest),
            ModuleType::Gallery,
            MediaType::Photo,
            Visibility::AllGuests,
        );
        $gone->recordOriginal('image/jpeg', 5000);
        $gone->markDeleted(new DateTimeImmutable());
        $now = new DateTimeImmutable();
        $open = new Upload(
            self::fresh($guest),
            ModuleType::Gallery,
            MediaType::Photo,
            Visibility::AllGuests,
            300,
            $now,
        );
        $expired = new Upload(
            self::fresh($guest),
            ModuleType::Gallery,
            MediaType::Photo,
            Visibility::AllGuests,
            7000,
            $now->modify('-2 days'),
        );
        foreach ([$media, $gone, $open, $expired] as $entity) {
            self::em()->persist($entity);
        }
        self::em()->flush();

        $usage = self::service(StorageUsage::class);
        self::assertSame(1550, $usage->forWedding(self::fresh($wedding), $now));
        self::assertSame(1550, $usage->forGuest(self::fresh($guest), $now));
    }

    private static function rowCount(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private function photo(Guest $guest, Visibility $visibility, bool $publish = false): Media
    {
        $media = Media::uploadedByGuest(self::fresh($guest), ModuleType::Gallery, MediaType::Photo, $visibility);
        $media->recordOriginal('image/jpeg', (int) filesize(self::PHOTO));
        $media->markReady();
        if ($publish) {
            $media->approve(new DateTimeImmutable());
        }

        self::em()->persist($media);
        self::em()->flush();
        self::service(MediaStorage::class)->store($media->mediaId(), MediaFile::Original, self::PHOTO);

        return $media;
    }

    /**
     * @param list<WeddingPermission> $permissions
     */
    private function login(array $permissions, Wedding $wedding): User
    {
        $user = $this->fixtures->user();
        $this->fixtures->member($user, self::fresh($wedding), $permissions);
        $this->client->loginUser(self::fresh($user));

        return $user;
    }
}
