<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\LoopGuardedAssetSaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;

#[CoversClass(LoopGuardedAssetSaver::class)]
final class LoopGuardedAssetSaverTest extends TestCase
{
    #[Test]
    public function savesInTheGuardedOrderAndRefreshesAdditionalLeases(): void
    {
        $calls = [];
        [$guard, $asset] = $this->collaborators($calls);

        (new LoopGuardedAssetSaver($guard))->save(
            $asset,
            static function (Asset $mutable) use (&$calls): void {
                unset($mutable);
                $calls[] = 'mutate';
            },
            ['versionNote' => 'test'],
            static function () use ($guard): void {
                $guard->refreshTarget('/target');
            },
        );

        self::assertSame([
            'markProcessing:42',
            'mutate',
            'refreshAsset:42',
            'refreshTarget:/target',
            'save:test',
            'markRecentlyMoved:42',
            'unmarkProcessing:42',
        ], $calls);
    }

    #[Test]
    public function alwaysUnmarksAndDoesNotMarkRecentlyMovedWhenSaveFails(): void
    {
        $calls = [];
        [$guard, $asset] = $this->collaborators($calls, new \RuntimeException('storage failed'));

        try {
            (new LoopGuardedAssetSaver($guard))->save($asset);
            self::fail('Expected the save exception to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('storage failed', $e->getMessage());
        }

        self::assertSame([
            'markProcessing:42',
            'refreshAsset:42',
            'save:',
            'unmarkProcessing:42',
        ], $calls);
    }

    #[Test]
    public function mutationFailureStillUnmarksBeforeAnyLeaseRefreshOrSave(): void
    {
        $calls = [];
        [$guard, $asset] = $this->collaborators($calls);

        try {
            (new LoopGuardedAssetSaver($guard))->save(
                $asset,
                static function (Asset $mutable) use (&$calls): never {
                    unset($mutable);
                    $calls[] = 'mutate';
                    throw new \RuntimeException('mutation failed');
                },
            );
            self::fail('Expected the mutation exception to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('mutation failed', $e->getMessage());
        }

        self::assertSame(['markProcessing:42', 'mutate', 'unmarkProcessing:42'], $calls);
    }

    #[Test]
    public function rejectsAnUnsavedAssetBeforeOpeningTheProcessingWindow(): void
    {
        $guard = $this->createMock(LoopGuard::class);
        $guard->expects(self::never())->method('markAssetProcessing');
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A persisted asset with a positive id is required.');

        (new LoopGuardedAssetSaver($guard))->save($asset);
    }

    /** @return array{LoopGuard, Asset} */
    private function collaborators(array &$calls, ?\Throwable $saveFailure = null): array
    {
        $guard = $this->createMock(LoopGuard::class);
        $guard->method('markAssetProcessing')->willReturnCallback(static function (int $id) use (&$calls): void {
            $calls[] = 'markProcessing:' . $id;
        });
        $guard->method('refreshAsset')->willReturnCallback(static function (int $id) use (&$calls): void {
            $calls[] = 'refreshAsset:' . $id;
        });
        $guard->method('refreshTarget')->willReturnCallback(static function (string $path) use (&$calls): void {
            $calls[] = 'refreshTarget:' . $path;
        });
        $guard->method('markAssetRecentlyMoved')->willReturnCallback(static function (int $id) use (&$calls): void {
            $calls[] = 'markRecentlyMoved:' . $id;
        });
        $guard->method('unmarkAssetProcessing')->willReturnCallback(static function (int $id) use (&$calls): void {
            $calls[] = 'unmarkProcessing:' . $id;
        });

        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(42);
        $asset->method('save')->willReturnCallback(static function (array $parameters = []) use (&$calls, $asset, $saveFailure): Asset {
            $calls[] = 'save:' . ($parameters['versionNote'] ?? '');
            if ($saveFailure !== null) {
                throw $saveFailure;
            }

            return $asset;
        });

        return [$guard, $asset];
    }
}
