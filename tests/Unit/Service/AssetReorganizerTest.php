<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\AssetDependencyResolver;
use Oronts\AssetPilotBundle\Service\AssetReorganizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssetReorganizer::class)]
final class AssetReorganizerTest extends TestCase
{
    #[Test]
    public function folderSelectionReturnsEachOwnerOnceInDiscoveryOrder(): void
    {
        $service = $this->selector(
            [1, 2],
            [1 => [10], 2 => [10, 20]],
        );

        $selection = $service->selectFolder('/Staging', 50);

        self::assertSame(2, $selection['assetCount']);
        self::assertSame([10, 20], $selection['objectIds']);
    }

    #[Test]
    public function folderSelectionUsesConfiguredDefaultLimit(): void
    {
        $service = $this->selector([1, 2, 3], [1 => [10], 2 => [20], 3 => [30]], defaultLimit: 2);

        self::assertSame(
            ['assetCount' => 2, 'objectIds' => [10, 20], 'truncated' => false],
            $service->selectFolder('/Staging'),
        );
    }

    #[Test]
    public function folderScanStopsAtTheCandidateBudgetAndReportsTruncated(): void
    {
        // A workspace-restricted user sees none of a large folder; the scan stops at the budget.
        $service = $this->selector(range(1, 100), [], visibleAssetIds: [], maxCandidates: 10);

        $selection = $service->selectFolder('/Staging', 50);

        self::assertSame(0, $selection['assetCount']);
        self::assertTrue($selection['truncated']);
    }

    #[Test]
    public function explicitSelectionDropsInvalidDuplicateAndHiddenAssets(): void
    {
        $service = $this->selector(
            [],
            [5 => [10], 6 => [20]],
            visibleAssetIds: [5],
        );

        $selection = $service->selectAssets([5, 5, 6, 0, -3]);

        self::assertSame(1, $selection['assetCount']);
        self::assertSame([10], $selection['objectIds']);
    }

    /**
     * @param list<int>             $folderAssetIds
     * @param array<int, list<int>> $ownersByAsset
     * @param list<int>|null        $visibleAssetIds
     */
    private function selector(
        array $folderAssetIds,
        array $ownersByAsset,
        ?array $visibleAssetIds = null,
        int $defaultLimit = 100,
        int $maxCandidates = 5000,
    ): AssetReorganizer {
        $resolver = $this->createMock(AssetDependencyResolver::class);
        $resolver->method('dependentObjectIds')->willReturnCallback(
            static fn (int $assetId): array => $ownersByAsset[$assetId] ?? [],
        );

        return new class (
            $resolver,
            $this->createMock(ElementAuthorization::class),
            $folderAssetIds,
            $visibleAssetIds,
            $defaultLimit,
            $maxCandidates,
        ) extends AssetReorganizer {
            /** @param list<int> $folderAssetIds @param list<int>|null $visibleAssetIds */
            public function __construct(
                AssetDependencyResolver $resolver,
                ElementAuthorization $authorization,
                private readonly array $folderAssetIds,
                private readonly ?array $visibleAssetIds,
                int $defaultLimit,
                int $maxCandidates,
            ) {
                parent::__construct($resolver, $authorization, $defaultLimit, $maxCandidates);
            }

            protected function rawAssetIdsInFolder(string $folderPath, int $offset, int $limit): array
            {
                return array_slice($this->folderAssetIds, $offset, $limit);
            }

            protected function isAssetVisible(int $assetId): bool
            {
                return $this->visibleAssetIds === null || in_array($assetId, $this->visibleAssetIds, true);
            }
        };
    }
}
