<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Exception\AssetDeletionFenceLostException;

/**
 * Cross-pod deletion fence: a deleter commits a token-owned tombstone for an asset before its final
 * safety re-read, and every dependency writer rejects a save that would reference a fenced (being
 * deleted) or missing asset. Backed by one committed row per asset in the shared primary database, so
 * it does not depend on process-local state or a specific pooled connection.
 */
interface AssetDeletionFenceInterface
{
    /**
     * Claim exclusive deletion ownership of an asset. Returns the owner token on success, or null when
     * another operation already owns the fence (busy). Fails closed (throws) if called inside an ambient
     * transaction, because a tombstone hidden in an uncommitted outer transaction cannot fence other pods.
     */
    public function acquire(int $assetId, string $operation): ?string;

    /**
     * Extend the lease immediately before the actual delete.
     *
     * @throws AssetDeletionFenceLostException if the fence row is gone or now owned by a different token
     */
    public function refreshOrFail(int $assetId, string $ownerToken): void;

    public function release(int $assetId, string $ownerToken): void;

    /**
     * Writer-side guard: reject a save whose outgoing asset references include any missing or being-deleted
     * asset. Uses the primary connection; never a cache or read replica.
     *
     * @param list<int> $targetIds
     *
     * @throws \Pimcore\Model\Element\ValidationException on the first missing or fenced target
     */
    public function assertWritableTargets(array $targetIds): void;

    /**
     * Asset IDs whose fence has expired or whose asset row no longer exists, ordered oldest-first and
     * bounded by the batch size. Reaping candidates for the maintenance task.
     *
     * @return list<int>
     */
    public function expiredFenceCandidates(int $batchSize): array;

    /**
     * Compare-and-delete one stale fence row: removed only if still expired (or its asset is gone) and
     * still owned by the re-read token, so an owner that refreshed concurrently is never reaped. Returns
     * whether a row was removed.
     */
    public function reapAsset(int $assetId): bool;
}
