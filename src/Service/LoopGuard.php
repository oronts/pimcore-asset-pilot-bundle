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
    private const int RECENTLY_MOVED_TTL = 300; // 5 minutes — prevents async ping-pong

    /** @var array<string, LockInterface> locks held by this process, keyed by resource */
    private array $heldLocks = [];

    /** @var array<string, positive-int> */
    private array $lockDepth = [];

    /** @var array<string, string> durable operation-run-item claim tokens held by this process, keyed by "runId:itemKey" */
    private array $operationRunItemTokens = [];

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LockFactory $lockFactory,
        private readonly float $lockTtl = 60.0,
    ) {
        if ($this->lockTtl <= 0) {
            throw new \InvalidArgumentException('The lock TTL must be greater than zero.');
        }
    }

    public function acquireObject(int $objectId): bool
    {
        return $this->acquire('asset_pilot_lock_object_' . $objectId);
    }

    public function releaseObject(int $objectId): void
    {
        $this->release('asset_pilot_lock_object_' . $objectId);
    }

    /**
     * Extend the object lock's lease so a long organize run does not let the configured lease
     * (idempotency.lock_ttl) expire while still working. Throws if the lock was already lost (TTL
     * expired and another job took it), which correctly aborts the now-unsafe run rather than risk a
     * double-move.
     */
    public function refreshObject(int $objectId): void
    {
        $this->refresh('asset_pilot_lock_object_' . $objectId);
        // The processing and dirty markers otherwise lapse at lock_ttl while a heartbeated run can outlive it, so a
        // save that coalesced into this run would be dropped once its marker expired mid-pass. Keep them alive for
        // as long as the object lock is refreshed. Refresh only when already set, so this never creates a marker
        // (refreshObject is also called from the repoint path, which does not mark the object processing).
        $this->refreshMarkerIfSet($this->objectKey($objectId));
        $this->refreshMarkerIfSet($this->dirtyObjectKey($objectId));
    }

    private function refreshMarkerIfSet(string $key): void
    {
        $item = $this->cache->getItem($key);
        if ($item->isHit()) {
            $item->set(true)->expiresAfter((int) ceil($this->lockTtl));
            $this->cache->save($item);
        }
    }

    public function acquireAsset(int $assetId): bool
    {
        return $this->acquire('asset_pilot_lock_asset_' . $assetId);
    }

    public function releaseAsset(int $assetId): void
    {
        $this->release('asset_pilot_lock_asset_' . $assetId);
    }

    public function refreshAsset(int $assetId): void
    {
        $this->refresh('asset_pilot_lock_asset_' . $assetId);
    }

    public function acquireReferrer(string $type, int $id): bool
    {
        return $this->acquire($this->referrerResource($type, $id));
    }

    public function releaseReferrer(string $type, int $id): void
    {
        $this->release($this->referrerResource($type, $id));
    }

    public function refreshReferrer(string $type, int $id): void
    {
        $this->refresh($this->referrerResource($type, $id));
    }

    public function acquireTarget(string $targetPath): bool
    {
        return $this->acquire($this->targetResource($targetPath));
    }

    public function releaseTarget(string $targetPath): void
    {
        $this->release($this->targetResource($targetPath));
    }

    public function refreshTarget(string $targetPath): void
    {
        $this->refresh($this->targetResource($targetPath));
    }

    public function acquireOperationRunItem(string $runId, string $itemKey): bool
    {
        return $this->acquire($this->operationRunItemResource($runId, $itemKey));
    }

    public function releaseOperationRunItem(string $runId, string $itemKey): void
    {
        $resource = $this->operationRunItemResource($runId, $itemKey);
        $this->release($resource);
        if (!isset($this->heldLocks[$resource])) {
            unset($this->operationRunItemTokens[$runId . ':' . $itemKey]);
        }
    }

    public function refreshOperationRunItem(string $runId, string $itemKey): void
    {
        $this->refresh($this->operationRunItemResource($runId, $itemKey));
    }

    /**
     * Mint (or return the already-held) durable claim token for an operation-run item the worker is about
     * to begin. The worker stamps it via OperationRunStore::resumeItem and holds it for the item's
     * lifetime so its heartbeat and completion are fenced: a redelivery that reclaims the item mints a new
     * token, and the abandoned worker's lease renewal then fails and aborts it before its next write.
     * Cleared when the item lock is fully released. Only claimed items get a token; pre-claim
     * cancel/skip completions read null and stay unfenced so they still terminalize a queued item.
     */
    public function beginOperationRunItemLease(string $runId, string $itemKey): string
    {
        return $this->operationRunItemTokens[$runId . ':' . $itemKey] ??= bin2hex(random_bytes(16));
    }

    public function operationRunItemToken(string $runId, string $itemKey): ?string
    {
        return $this->operationRunItemTokens[$runId . ':' . $itemKey] ?? null;
    }

    private function acquire(string $resource): bool
    {
        if (isset($this->heldLocks[$resource])) {
            ++$this->lockDepth[$resource];

            return true;
        }

        $lock = $this->lockFactory->createLock($resource, $this->lockTtl);
        if (!$lock->acquire()) {
            return false;
        }

        $this->heldLocks[$resource] = $lock;
        $this->lockDepth[$resource] = 1;

        return true;
    }

    private function release(string $resource): void
    {
        $lock = $this->heldLocks[$resource] ?? null;
        if ($lock === null) {
            return;
        }

        if ($this->lockDepth[$resource] > 1) {
            --$this->lockDepth[$resource];

            return;
        }

        unset($this->heldLocks[$resource], $this->lockDepth[$resource]);

        // Best-effort: a failed release (lock already lost/expired) self-heals via the TTL.
        try {
            $lock->release();
        } catch (\Throwable) {
        }
    }

    private function refresh(string $resource): void
    {
        ($this->heldLocks[$resource] ?? null)?->refresh($this->lockTtl);
    }

    private function targetResource(string $targetPath): string
    {
        $normalizedPath = '/' . ltrim(preg_replace('#/+#', '/', trim($targetPath)) ?? '', '/');

        return 'asset_pilot_lock_target_' . hash('sha256', $normalizedPath);
    }

    private function referrerResource(string $type, int $id): string
    {
        return match ($type) {
            'asset' => 'asset_pilot_lock_asset_' . $id,
            'object' => 'asset_pilot_lock_object_' . $id,
            'document' => 'asset_pilot_lock_document_' . $id,
            default => 'asset_pilot_lock_referrer_' . hash('sha256', $type . ':' . $id),
        };
    }

    private function operationRunItemResource(string $runId, string $itemKey): string
    {
        [$type, $rawId] = array_pad(explode(':', $itemKey, 2), 2, null);
        $id = is_string($rawId) && ctype_digit($rawId) ? (int) $rawId : 0;

        return match (true) {
            $type === 'object' && $id > 0 => 'asset_pilot_lock_object_' . $id,
            $type === 'asset' && $id > 0 => 'asset_pilot_lock_asset_' . $id,
            default => 'asset_pilot_lock_operation_run_item_' . hash('sha256', $runId . ':' . $itemKey),
        };
    }

    public function isProcessingAsset(int $assetId): bool
    {
        return $this->cache->hasItem($this->assetKey($assetId));
    }

    public function markAssetProcessing(int $assetId): void
    {
        $item = $this->cache->getItem($this->assetKey($assetId));
        $item->set(true)->expiresAfter((int) ceil($this->lockTtl));
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
        $item->set(true)->expiresAfter((int) ceil($this->lockTtl));
        $this->cache->save($item);
    }

    public function unmarkObjectProcessing(int $objectId): void
    {
        $this->cache->deleteItem($this->objectKey($objectId));
    }

    public function markObjectDirty(int $objectId): void
    {
        $item = $this->cache->getItem($this->dirtyObjectKey($objectId));
        $item->set(true)->expiresAfter((int) ceil($this->lockTtl));
        $this->cache->save($item);
    }

    public function consumeObjectDirty(int $objectId): bool
    {
        if (!$this->isObjectDirty($objectId)) {
            return false;
        }

        $this->clearObjectDirty($objectId);

        return true;
    }

    public function isObjectDirty(int $objectId): bool
    {
        return $this->cache->hasItem($this->dirtyObjectKey($objectId));
    }

    public function clearObjectDirty(int $objectId): void
    {
        $this->cache->deleteItem($this->dirtyObjectKey($objectId));
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

    private function dirtyObjectKey(int $id): string
    {
        return 'asset_pilot.dirty_object.' . $id;
    }
}
