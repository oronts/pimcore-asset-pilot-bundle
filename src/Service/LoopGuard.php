<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Redis-backed loop prevention for bidirectional event handling.
 *
 * Prevents infinite recursion when:
 * - Asset move → asset postUpdate → AssetUploadListener → organize → asset move ...
 * - Object organize → DataObjectSaveListener → organize ...
 *
 * Uses cache (Redis) so it works across PHP-FPM workers, Messenger workers,
 * and horizontally scaled pods.
 */
class LoopGuard
{
    private const int DEFAULT_TTL = 60; // seconds — auto-expires to prevent deadlocks
    private const int RECENTLY_MOVED_TTL = 300; // 5 minutes — prevents async ping-pong
    private const int DISPATCH_DEDUP_TTL = 10; // seconds — prevents duplicate message dispatches

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {}

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
