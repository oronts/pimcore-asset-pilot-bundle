<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\DriverManager;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Security\ActorContextProvider;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\AssetSearchService;
use Oronts\AssetPilotBundle\Service\Query\AssetWorkspaceQueryScope;
use Oronts\AssetPilotBundle\Service\Query\AuthorizedAssetPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\User;
use Psr\Log\NullLogger;

#[CoversClass(AssetSearchService::class)]
class AssetSearchServiceTest extends TestCase
{
    /**
     * @param list<int>|null $visibleIds     workspace-view ids for a scoped user; null builds a System actor
     * @param list<int>      $nativelyDenied ids the SQL scope allows but native isAllowed('view') denies
     */
    private function service(?array $visibleIds = null, array $nativelyDenied = []): AssetSearchService
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $conn->executeStatement('CREATE TABLE assets (id INTEGER PRIMARY KEY, path TEXT, filename TEXT, type TEXT, mimetype TEXT, creationDate INTEGER, modificationDate INTEGER)');
        $conn->executeStatement('CREATE TABLE properties (cid INTEGER, ctype TEXT, name TEXT, data TEXT)');
        $conn->executeStatement('CREATE TABLE dependencies (sourceid INTEGER, sourcetype TEXT, targetid INTEGER, targettype TEXT)');
        $conn->executeStatement('CREATE TABLE asset_pilot_checksum (asset_id INTEGER, file_size INTEGER, size_known INTEGER, indexed_at TEXT)');
        $conn->executeStatement('CREATE TABLE users_workspaces_asset (userId INTEGER, cpath TEXT, view INTEGER)');
        foreach ([[1, 'a.png', 'image'], [2, 'b.pdf', 'document'], [3, 'c.jpg', 'image'], [4, 'd.png', 'image']] as [$id, $fn, $type]) {
            $conn->insert('assets', ['id' => $id, 'path' => '/x/', 'filename' => $fn, 'type' => $type, 'mimetype' => '', 'creationDate' => 0, 'modificationDate' => $id]);
        }
        // An object references assets 1 and 3; a document references asset 4 (any element counts as a
        // reference, matching UnusedAssetFinder); asset 2 is unreferenced.
        $conn->insert('dependencies', ['sourceid' => 100, 'sourcetype' => 'object', 'targetid' => 1, 'targettype' => 'asset']);
        $conn->insert('dependencies', ['sourceid' => 100, 'sourcetype' => 'object', 'targetid' => 3, 'targettype' => 'asset']);
        $conn->insert('dependencies', ['sourceid' => 200, 'sourcetype' => 'document', 'targetid' => 4, 'targettype' => 'asset']);

        $actor = $visibleIds === null ? ActorContext::system() : ActorContext::user(7);
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn($actor);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (Asset $asset, string $permission): bool => $permission === 'view' && !in_array((int) $asset->getId(), $nativelyDenied, true),
        );
        $actors = $this->createMock(ActorContextProvider::class);
        if ($visibleIds !== null) {
            $user = (new User())->setId(7)->setActive(true)->setAdmin(false)->setPermissions(['assets']);
            $actors->method('resolveUser')->with($actor)->willReturn($user);
            $filenames = [1 => 'a.png', 2 => 'b.pdf', 3 => 'c.jpg', 4 => 'd.png'];
            foreach ($visibleIds as $id) {
                $conn->insert('users_workspaces_asset', [
                    'userId' => 7,
                    'cpath' => '/x/' . ($filenames[$id] ?? $id . '.bin'),
                    'view' => 1,
                ]);
            }
        }

        $stubs = [];
        foreach ([1, 2, 3, 4] as $id) {
            $stub = $this->createStub(Asset::class);
            $stub->method('getId')->willReturn($id);
            $stubs[$id] = $stub;
        }
        $scope = new AssetWorkspaceQueryScope($conn, $authorization, $actors);

        return new AssetSearchService(
            $conn,
            new NullLogger(),
            $scope,
            new AuthorizedAssetPage($authorization, $scope, static fn (int $id): ?Asset => $stubs[$id] ?? null),
        );
    }

    #[Test]
    public function emitsAssetTimestampsAsRfc3339Utc(): void
    {
        $byId = [];
        foreach ($this->service()->search([])['items'] as $item) {
            $byId[$item['id']] = $item;
        }

        self::assertSame('1970-01-01T00:00:02+00:00', $byId[2]['modified_at'], 'modificationDate unix 2 must serialize as RFC 3339 UTC');
        self::assertNull($byId[2]['created_at'], 'a zero creationDate stays null rather than serializing the epoch');
    }

    /** @param array{items: array<int, array<string, mixed>>} $result @return list<int> */
    private function ids(array $result): array
    {
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $result['items']);
        sort($ids);

        return $ids;
    }

    #[Test]
    public function filtersByReferencedState(): void
    {
        $service = $this->service();

        self::assertSame([1, 3, 4], $this->ids($service->search(['referenced' => 'referenced'])), 'object- and document-referenced both count');
        self::assertSame([2], $this->ids($service->search(['referenced' => 'unreferenced'])));
        self::assertSame([1, 2, 3, 4], $this->ids($service->search([])), 'no filter returns every asset');
    }

    #[Test]
    public function filtersByExtension(): void
    {
        $service = $this->service();

        self::assertSame([2], $this->ids($service->search(['extension' => 'pdf'])));
        self::assertSame([1, 4], $this->ids($service->search(['extension' => '.png'])), 'a leading dot is tolerated');
    }

    #[Test]
    public function scopedUserGetsNativelyAuthorizedRowsWithoutALeakingTotal(): void
    {
        $service = $this->service([2, 4]);

        $first = $service->search([], 1, 1);
        $second = $service->search([], 2, 1);

        self::assertSame([4], $this->ids($first));
        self::assertSame([2], $this->ids($second));
        self::assertNull($first['total'], 'a scoped user must not receive an SQL-count-derived total');
        self::assertNull($first['pages'], 'pages is undefined when the total is hidden');
        self::assertTrue($first['hasMore'], 'a second workspace-visible asset remains, so hasMore is true');
        self::assertFalse($second['hasMore']);
    }

    #[Test]
    public function nativeViewDenialHidesARowTheWorkspaceScopeAllowed(): void
    {
        $service = $this->service([2, 4], nativelyDenied: [4]);

        $result = $service->search([], 1, 50);

        self::assertSame([2], $this->ids($result), 'asset 4 is in the workspace but natively denied, so it must not be disclosed');
        self::assertNull($result['total']);
    }

    #[Test]
    public function adminActorKeepsTheExactTotal(): void
    {
        $result = $this->service()->search([], 1, 2);

        self::assertSame(4, $result['total'], 'System/admin short-circuits the scope, so the SQL total is authoritative');
        self::assertSame(2, $result['pages']);
        self::assertTrue($result['hasMore']);
    }
}
