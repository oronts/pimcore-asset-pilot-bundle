<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Service\LoopGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(LoopGuard::class)]
class LoopGuardTest extends TestCase
{
    private CacheItemPoolInterface $cache;
    private LoopGuard $guard;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->guard = new LoopGuard($this->cache, new LockFactory(new InMemoryStore()));
    }

    #[Test]
    public function refreshObjectKeepsExistingMarkersAliveButNeverCreatesThem(): void
    {
        $guard = new LoopGuard(new ArrayAdapter(), new LockFactory(new InMemoryStore()));

        // With no markers set, refreshing the object lock must not fabricate a processing or dirty marker.
        $guard->refreshObject(42);
        self::assertFalse($guard->isProcessingObject(42));
        self::assertFalse($guard->isObjectDirty(42));

        // Once a coalesced save has marked the object, the heartbeat re-arm keeps both markers present.
        $guard->markObjectProcessing(42);
        $guard->markObjectDirty(42);
        $guard->refreshObject(42);
        self::assertTrue($guard->isProcessingObject(42));
        self::assertTrue($guard->isObjectDirty(42));
    }

    #[Test]
    public function tryCoalesceFoldsIntoAnInFlightRunOnlyWhileItsMarkerStands(): void
    {
        $guard = new LoopGuard(new ArrayAdapter(), new LockFactory(new InMemoryStore()));

        // No run in flight: nothing to coalesce into, and the object is left un-dirtied.
        self::assertFalse($guard->tryCoalesceIntoInFlightRun(42));
        self::assertFalse($guard->isObjectDirty(42));

        // A run is in flight: the save is folded in and the object is marked dirty for that run to drain.
        $guard->markObjectDispatched(42);
        self::assertTrue($guard->tryCoalesceIntoInFlightRun(42));
        self::assertTrue($guard->isObjectDirty(42));

        // The run finalized and cleared its marker: a later save can no longer coalesce and must record its own run.
        $guard->clearObjectDispatched(42);
        self::assertFalse($guard->tryCoalesceIntoInFlightRun(42));
    }

    #[Test]
    public function acquireObjectIsExclusiveAcrossJobsAndReleasableByTheOwner(): void
    {
        $store = new InMemoryStore();
        $jobA = new LoopGuard($this->cache, new LockFactory($store));
        $jobB = new LoopGuard($this->cache, new LockFactory($store));

        self::assertTrue($jobA->acquireObject(42));
        self::assertFalse($jobB->acquireObject(42), 'a second job must skip while the first holds the lock');

        $jobA->releaseObject(42);
        self::assertTrue($jobB->acquireObject(42), 'the lock is free once the owner releases it');
    }

    #[Test]
    public function operationRunItemLocksAreExclusiveAndOwnerReleased(): void
    {
        $store = new InMemoryStore();
        $jobA = new LoopGuard($this->cache, new LockFactory($store));
        $jobB = new LoopGuard($this->cache, new LockFactory($store));

        self::assertTrue($jobA->acquireOperationRunItem('run-1', 'object:42'));
        self::assertFalse($jobB->acquireOperationRunItem('run-1', 'object:42'));
        self::assertFalse($jobB->acquireOperationRunItem('run-2', 'object:42'));
        self::assertFalse($jobB->acquireObject(42));
        self::assertTrue($jobB->acquireOperationRunItem('run-1', 'object:43'));

        $jobA->refreshOperationRunItem('run-1', 'object:42');
        $jobA->releaseOperationRunItem('run-1', 'object:42');
        self::assertTrue($jobB->acquireOperationRunItem('run-1', 'object:42'));
    }

    #[Test]
    public function operationRunItemLeaseTokenIsMintedOnBeginStableAndClearedOnRelease(): void
    {
        self::assertNull($this->guard->operationRunItemToken('run-1', 'object:42'), 'no token before the item is begun');

        self::assertTrue($this->guard->acquireOperationRunItem('run-1', 'object:42'));
        $token = $this->guard->beginOperationRunItemLease('run-1', 'object:42');

        self::assertNotSame('', $token);
        self::assertSame($token, $this->guard->operationRunItemToken('run-1', 'object:42'), 'the same token is held for the item');
        self::assertSame($token, $this->guard->beginOperationRunItemLease('run-1', 'object:42'), 'beginning again keeps the same token');
        self::assertNotSame($token, $this->guard->beginOperationRunItemLease('run-1', 'object:43'), 'a different item gets its own token');

        $this->guard->releaseOperationRunItem('run-1', 'object:42');
        self::assertNull($this->guard->operationRunItemToken('run-1', 'object:42'), 'the token is cleared once the item lock is released');
    }

    #[Test]
    public function releasingALockYouDoNotOwnDoesNotFreeTheCurrentHolder(): void
    {
        $store = new InMemoryStore();
        $jobA = new LoopGuard($this->cache, new LockFactory($store));
        $jobB = new LoopGuard($this->cache, new LockFactory($store));

        self::assertTrue($jobA->acquireAsset(7));
        $jobB->releaseAsset(7); // jobB never acquired — must be a no-op, not free jobA's lock

        self::assertFalse($jobB->acquireAsset(7), 'jobA still holds the lock');
    }

    #[Test]
    public function objectAndAssetLocksUseSeparateResources(): void
    {
        self::assertTrue($this->guard->acquireObject(1));
        self::assertTrue($this->guard->acquireAsset(1));
    }

    #[Test]
    public function targetLocksNormalizeEquivalentPathsAndAreExclusive(): void
    {
        $store = new InMemoryStore();
        $jobA = new LoopGuard($this->cache, new LockFactory($store));
        $jobB = new LoopGuard($this->cache, new LockFactory($store));

        self::assertTrue($jobA->acquireTarget('/products//images/photo.jpg'));
        self::assertFalse($jobB->acquireTarget('products/images/photo.jpg'));

        $jobA->releaseTarget('/products/images/photo.jpg');
        self::assertTrue($jobB->acquireTarget('/products/images/photo.jpg'));
    }

    #[Test]
    public function nestedAcquisitionOnlyReleasesAfterMatchingRelease(): void
    {
        $store = new InMemoryStore();
        $jobA = new LoopGuard($this->cache, new LockFactory($store));
        $jobB = new LoopGuard($this->cache, new LockFactory($store));

        self::assertTrue($jobA->acquireAsset(12));
        self::assertTrue($jobA->acquireAsset(12));

        $jobA->releaseAsset(12);
        self::assertFalse($jobB->acquireAsset(12));

        $jobA->releaseAsset(12);
        self::assertTrue($jobB->acquireAsset(12));
    }

    #[Test]
    public function refreshAssetAndTargetAreNoOpsWithoutLocks(): void
    {
        $this->expectNotToPerformAssertions();

        $this->guard->refreshAsset(99);
        $this->guard->refreshTarget('/missing.jpg');
    }

    #[Test]
    public function lockTtlMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LoopGuard($this->cache, new LockFactory(new InMemoryStore()), 0);
    }

    #[Test]
    public function configuredTtlIsUsedForProcessingFlags(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('set')->with(true)->willReturn($item);
        $item->expects(self::once())->method('expiresAfter')->with(91)->willReturn($item);
        $this->cache->method('getItem')->willReturn($item);
        $this->cache->expects(self::once())->method('save')->with($item);

        $guard = new LoopGuard($this->cache, new LockFactory(new InMemoryStore()), 90.1);
        $guard->markAssetProcessing(42);
    }

    #[Test]
    public function dirtyObjectMarkerCanBeConsumedExactlyOnce(): void
    {
        $guard = new LoopGuard(new ArrayAdapter(), new LockFactory(new InMemoryStore()));

        self::assertFalse($guard->consumeObjectDirty(42));
        $guard->markObjectDirty(42);
        self::assertTrue($guard->consumeObjectDirty(42));
        self::assertFalse($guard->consumeObjectDirty(42));
    }

    #[Test]
    public function refreshObjectIsANoOpWhenNoLockIsHeld(): void
    {
        $this->expectNotToPerformAssertions();
        $this->guard->refreshObject(99); // must not throw when nothing is held
    }

    #[Test]
    public function refreshObjectExtendsAHeldLock(): void
    {
        self::assertTrue($this->guard->acquireObject(5));
        $this->guard->refreshObject(5); // still ours -> no exception

        self::assertTrue(true);
    }

    #[Test]
    public function isProcessingAssetReturnsFalseWhenNotCached(): void
    {
        $this->cache->method('hasItem')
            ->with('asset_pilot.loop_guard.asset.42')
            ->willReturn(false);

        self::assertFalse($this->guard->isProcessingAsset(42));
    }

    #[Test]
    public function isProcessingAssetReturnsTrueWhenCached(): void
    {
        $this->cache->method('hasItem')
            ->with('asset_pilot.loop_guard.asset.42')
            ->willReturn(true);

        self::assertTrue($this->guard->isProcessingAsset(42));
    }

    #[Test]
    public function markAssetProcessingSavesItem(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->expects(self::once())->method('set')->with(true)->willReturn($item);
        $item->expects(self::once())->method('expiresAfter')->with(60)->willReturn($item);

        $this->cache->method('getItem')
            ->with('asset_pilot.loop_guard.asset.42')
            ->willReturn($item);

        $this->cache->expects(self::once())->method('save')->with($item);

        $this->guard->markAssetProcessing(42);
    }

    #[Test]
    public function unmarkAssetProcessingDeletesItem(): void
    {
        $this->cache->expects(self::once())
            ->method('deleteItem')
            ->with('asset_pilot.loop_guard.asset.42');

        $this->guard->unmarkAssetProcessing(42);
    }

    #[Test]
    public function isProcessingObjectReturnsFalseWhenNotCached(): void
    {
        $this->cache->method('hasItem')
            ->with('asset_pilot.loop_guard.object.99')
            ->willReturn(false);

        self::assertFalse($this->guard->isProcessingObject(99));
    }

    #[Test]
    public function isProcessingObjectReturnsTrueWhenCached(): void
    {
        $this->cache->method('hasItem')
            ->with('asset_pilot.loop_guard.object.99')
            ->willReturn(true);

        self::assertTrue($this->guard->isProcessingObject(99));
    }

    #[Test]
    public function markObjectProcessingSavesItem(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->expects(self::once())->method('set')->with(true)->willReturn($item);
        $item->expects(self::once())->method('expiresAfter')->with(60)->willReturn($item);

        $this->cache->method('getItem')
            ->with('asset_pilot.loop_guard.object.99')
            ->willReturn($item);

        $this->cache->expects(self::once())->method('save')->with($item);

        $this->guard->markObjectProcessing(99);
    }

    #[Test]
    public function unmarkObjectProcessingDeletesItem(): void
    {
        $this->cache->expects(self::once())
            ->method('deleteItem')
            ->with('asset_pilot.loop_guard.object.99');

        $this->guard->unmarkObjectProcessing(99);
    }

    #[Test]
    public function markAssetRecentlyMovedUses300SecondTtl(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->expects(self::once())->method('set')->with(true)->willReturn($item);
        $item->expects(self::once())->method('expiresAfter')->with(300)->willReturn($item);

        $this->cache->method('getItem')
            ->with('asset_pilot.recently_moved.42')
            ->willReturn($item);

        $this->cache->expects(self::once())->method('save')->with($item);

        $this->guard->markAssetRecentlyMoved(42);
    }

    #[Test]
    public function wasAssetRecentlyMovedChecksCache(): void
    {
        $this->cache->method('hasItem')
            ->with('asset_pilot.recently_moved.42')
            ->willReturn(true);

        self::assertTrue($this->guard->wasAssetRecentlyMoved(42));
    }

    #[Test]
    public function wasAssetRecentlyMovedReturnsFalseWhenNotCached(): void
    {
        $this->cache->method('hasItem')
            ->with('asset_pilot.recently_moved.42')
            ->willReturn(false);

        self::assertFalse($this->guard->wasAssetRecentlyMoved(42));
    }
}
