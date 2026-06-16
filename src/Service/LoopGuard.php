<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Loop prevention and idempotency for the bidirectional event pipeline.
 *
 * Two layers, each with a distinct job:
 * - Symfony Lock (acquire-or-skip, owner-token release): real mutual exclusion so two concurrent or
 *   redelivered jobs cannot both organize the same object or both move the same asset.
 * - PSR-6 cache flags (Redis): cross-process *signals* the listeners read to short-circuit the
 *   postUpdate that the organizer's own save triggers (processing flag, recently-moved, dispatch dedup).
 *
 * Both stores must be shared across workers/pods (Redis) for the guarantees to hold cluster-wide.
 */
class LoopGuard
{
    private const int DEFAULT_TTL = 60; // seconds — auto-expires to prevent deadlocks
    private const int RECENTLY_MOVED_TTL = 300; // 5 minutes — prevents async ping-pong
    private const int DISPATCH_DEDUP_TTL = 10; // seconds — prevents duplicate message dispatches

    /** @var array<string, LockInterface> locks held by this process, keyed by resource */
    private array $heldLocks = [];

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LockFactory $lockFactory,
    ) {}

    public function acquireObject(int $objectId): bool
    {
        return $this->acquire('asset_pilot_lock_object_' . $objectId);
    }

    public function releaseObject(int $objectId): void
    {
        $this->release('asset_pilot_lock_object_' . $objectId);
    }

    /**
     * Extend the object lock's lease so a long organize run does not let the 60s lease expire while
     * still working. Throws if the lock was already lost (TTL expired and another job took it), which
     * correctly aborts the now-unsafe run rather than risk a double-move.
     */
    public function refreshObject(int $objectId): void
    {
        ($this->heldLocks['asset_pilot_lock_object_' . $objectId] ?? null)?->refresh();
    }

    public function acquireAsset(int $assetId): bool
    {
        return $this->acquire('asset_pilot_lock_asset_' . $assetId);
    }

    public function releaseAsset(int $assetId): void
    {
        $this->release('asset_pilot_lock_asset_' . $assetId);
    }

    private function acquire(string $resource): bool
    {
        if (isset($this->heldLocks[$resource])) {
            return true;
        }

        $lock = $this->lockFactory->createLock($resource, (float) self::DEFAULT_TTL);
        if (!$lock->acquire()) {
            return false;
        }

        $this->heldLocks[$resource] = $lock;

        return true;
    }

    private function release(string $resource): void
    {
        $lock = $this->heldLocks[$resource] ?? null;
        if ($lock === null) {
            return;
        }

        unset($this->heldLocks[$resource]);

        // Best-effort: a failed release (lock already lost/expired) self-heals via the TTL.
        try {
            $lock->release();
        } catch (\Throwable) {
        }
    }

    public function isProcessingAsset(int $assetId): bool
    {
        return $this->cache->hasItem($this->assetKey($assetId));
    }

    public function markAssetProcessing(int $assetId): void
    {
        $item = $this->cache->getItem($this->assetKey($assetId));
        $item->set(true)->expiresAfter(self::DEFAULT_TTL);
        $this->cache->save($item);
    }

    public function unmarkAssetProcessing(int $assetId): void
    {
        $this->cache->deleteItem($this->assetKey($assetId));
    }

    public function isProcessingObject(int $objectId): bool
    {
        return $this->cache->hasItem($this->objectKey($objectId));
    }

    public function markObjectProcessing(int $objectId): void
    {
        $item = $this->cache->getItem($this->objectKey($objectId));
        $item->set(true)->expiresAfter(self::DEFAULT_TTL);
        $this->cache->save($item);
    }

    public function unmarkObjectProcessing(int $objectId): void
    {
        $this->cache->deleteItem($this->objectKey($objectId));
    }

    /**
     * Mark an asset as recently moved by Asset Pilot (longer TTL).
     * Prevents async ping-pong when shared assets are referenced by multiple objects.
     */
    public function markAssetRecentlyMoved(int $assetId): void
    {
        $item = $this->cache->getItem($this->recentlyMovedKey($assetId));
        $item->set(true)->expiresAfter(self::RECENTLY_MOVED_TTL);
        $this->cache->save($item);
    }

    public function wasAssetRecentlyMoved(int $assetId): bool
    {
        return $this->cache->hasItem($this->recentlyMovedKey($assetId));
    }

    /**
     * Check if a message was recently dispatched for this object (prevents duplicate dispatches).
     */
    public function wasObjectRecentlyDispatched(int $objectId): bool
    {
        return $this->cache->hasItem($this->dispatchedKey($objectId));
    }

    public function markObjectDispatched(int $objectId): void
    {
        $item = $this->cache->getItem($this->dispatchedKey($objectId));
        $item->set(true)->expiresAfter(self::DISPATCH_DEDUP_TTL);
        $this->cache->save($item);
    }

    private function recentlyMovedKey(int $id): string
    {
        return 'asset_pilot.recently_moved.' . $id;
    }

    private function assetKey(int $id): string
    {
        return 'asset_pilot.loop_guard.asset.' . $id;
    }

    private function objectKey(int $id): string
    {
        return 'asset_pilot.loop_guard.object.' . $id;
    }

    private function dispatchedKey(int $id): string
    {
        return 'asset_pilot.dispatched.' . $id;
    }
}
