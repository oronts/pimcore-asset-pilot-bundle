<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service\Query;

use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Service\Query\AssetWorkspaceQueryScope;
use Oronts\AssetPilotBundle\Service\Query\AuthorizedAssetPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;

#[CoversClass(AuthorizedAssetPage::class)]
final class AuthorizedAssetPageTest extends TestCase
{
    #[Test]
    public function systemActorGetsExactTotalAndSkipsPerRowNativeChecks(): void
    {
        $authorization = $this->createMock(ElementAuthorizationInterface::class);
        $authorization->expects(self::never())->method('isAllowed');
        $scope = $this->createMock(AssetWorkspaceQueryScope::class);
        $scope->method('bypassesNativeAuthorization')->willReturn(true);
        $windowCalls = [];
        $page = new AuthorizedAssetPage($authorization, $scope, static fn (int $id): ?Asset => null);

        $result = $page->paginate(
            2,
            2,
            exactTotal: static fn (): int => 7,
            window: static function (int $offset, int $limit) use (&$windowCalls): array {
                $windowCalls[] = [$offset, $limit];

                return [['id' => 3], ['id' => 4]];
            },
            assetIdOf: static fn (array $row): ?int => (int) $row['id'],
        );

        self::assertSame([['id' => 3], ['id' => 4]], $result['items']);
        self::assertSame(7, $result['total']);
        self::assertTrue($result['hasMore']);
        self::assertSame([[2, 2]], $windowCalls, 'System path windows exactly the page size, no +1 probe.');
    }

    #[Test]
    public function scopedActorScansAcrossDeniedRowsToFillThePageAndFlagsAnAuthorizedSurplus(): void
    {
        // Authorized 10, 13, 14; denied 11, 12. A scoped actor must get a full page of authorized rows
        // even though denied rows sit between them, and hasMore must reflect an authorized surplus (14),
        // never the mere existence of a raw row.
        $assetsById = [];
        foreach ([10, 11, 12, 13, 14] as $id) {
            $assetsById[$id] = $this->createStub(Asset::class);
        }
        $allowed = [$assetsById[10], $assetsById[13], $assetsById[14]];
        $authorization = $this->createMock(ElementAuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (Asset $asset, string $permission): bool => $permission === 'view' && in_array($asset, $allowed, true),
        );
        $scope = $this->createMock(AssetWorkspaceQueryScope::class);
        $scope->method('bypassesNativeAuthorization')->willReturn(false);
        $rows = [['id' => 10], ['id' => 11], ['id' => 12], ['id' => 13], ['id' => 14]];
        $windowCalls = [];
        $page = new AuthorizedAssetPage(
            $authorization,
            $scope,
            static fn (int $id): ?Asset => $assetsById[$id] ?? null,
            maxCandidates: 1000,
            batchSize: 2,
        );

        $result = $page->paginate(
            1,
            2,
            exactTotal: static fn (): int => self::fail('A scoped actor must never trigger the SQL total.'),
            window: static function (int $offset, int $limit) use (&$windowCalls, $rows): array {
                $windowCalls[] = [$offset, $limit];

                return array_slice($rows, $offset, $limit);
            },
            assetIdOf: static fn (array $row): ?int => (int) $row['id'],
        );

        self::assertSame([['id' => 10], ['id' => 13]], $result['items'], 'Denied rows 11 and 12 must not appear; the page is filled from authorized rows.');
        self::assertNull($result['total'], 'A scoped actor never receives an SQL-count-derived total.');
        self::assertTrue($result['hasMore'], 'hasMore is set only after finding one more authorized row (14) past the page.');
        self::assertSame([[0, 2], [2, 2], [4, 2]], $windowCalls, 'The scanner walks raw chunks until it has enough authorized rows.');
    }

    #[Test]
    public function scopedActorReportsNoFurtherPageWhenAuthorizedRowsAreExhausted(): void
    {
        $assetsById = [];
        foreach ([10, 11, 13] as $id) {
            $assetsById[$id] = $this->createStub(Asset::class);
        }
        $allowed = [$assetsById[10], $assetsById[13]];
        $authorization = $this->createMock(ElementAuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (Asset $asset, string $permission): bool => $permission === 'view' && in_array($asset, $allowed, true),
        );
        $scope = $this->createMock(AssetWorkspaceQueryScope::class);
        $scope->method('bypassesNativeAuthorization')->willReturn(false);
        $rows = [['id' => 10], ['id' => 11], ['id' => 13]];
        $page = new AuthorizedAssetPage(
            $authorization,
            $scope,
            static fn (int $id): ?Asset => $assetsById[$id] ?? null,
            maxCandidates: 1000,
            batchSize: 2,
        );

        $result = $page->paginate(
            1,
            2,
            exactTotal: static fn (): int => self::fail('A scoped actor must never trigger the SQL total.'),
            window: static fn (int $offset, int $limit): array => array_slice($rows, $offset, $limit),
            assetIdOf: static fn (array $row): ?int => (int) $row['id'],
        );

        self::assertSame([['id' => 10], ['id' => 13]], $result['items']);
        self::assertNull($result['total']);
        self::assertFalse($result['hasMore'], 'With only two authorized rows and SQL exhausted, there is no further page.');
    }

    #[Test]
    public function iterateAuthorizedStreamsEveryVisibleRowToExhaustionAcrossBatchesWithoutACeiling(): void
    {
        // 250 raw rows; odd ids are natively denied. With batch 100 the source spans three window calls, and
        // every one of the 125 visible (even) rows must stream — an export is not bounded by the page ceiling.
        $assetsById = [];
        foreach (range(1, 250) as $id) {
            $assetsById[$id] = $this->createStub(Asset::class);
        }
        $authorization = $this->createMock(ElementAuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static function (Asset $asset, string $permission) use ($assetsById): bool {
                $id = array_search($asset, $assetsById, true);

                return $permission === 'view' && is_int($id) && $id % 2 === 0;
            },
        );
        $scope = $this->createMock(AssetWorkspaceQueryScope::class);
        $scope->method('bypassesNativeAuthorization')->willReturn(false);
        $rows = array_map(static fn (int $id): array => ['id' => $id], range(1, 250));
        $windowCalls = 0;
        $page = new AuthorizedAssetPage($authorization, $scope, static fn (int $id): ?Asset => $assetsById[$id] ?? null);
        $window = static function (int $offset, int $limit) use (&$windowCalls, $rows): array {
            ++$windowCalls;

            return array_slice($rows, $offset, $limit);
        };

        $streamed = iterator_to_array($page->iterateAuthorized($window, static fn (array $row): ?int => (int) $row['id'], batch: 100), false);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $streamed);

        self::assertSame(range(2, 250, 2), $ids, 'every visible row across all batches streams, no ceiling truncation');
        self::assertGreaterThanOrEqual(3, $windowCalls, 'the export walks all raw batches to exhaustion');
    }
}
