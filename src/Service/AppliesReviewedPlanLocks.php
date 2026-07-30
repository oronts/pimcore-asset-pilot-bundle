<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;

/**
 * Runs a reviewed asset mutation under its per-asset locks: with no expected fingerprints it runs the
 * operation directly; otherwise it acquires the reviewed locks, re-asserts every locked asset's mutation
 * fingerprint is unchanged, and only then runs the operation on the locked set. The using service must
 * expose `$this->reviewedLocks` (ReviewedAssetLockCoordinator) and `$this->mutationFingerprints`
 * (AssetMutationFingerprintService).
 */
trait AppliesReviewedPlanLocks
{
    /**
     * @template TResult
     *
     * @param list<int>                    $assetIds
     * @param array<string, string>|null   $expectedFingerprints
     * @param callable(list<int>): TResult $operation
     *
     * @return TResult
     */
    private function withReviewedPlanLocks(array $assetIds, ?array $expectedFingerprints, callable $operation): mixed
    {
        if ($expectedFingerprints === null) {
            return $operation([]);
        }

        return $this->reviewedLocks->run(
            $assetIds,
            static fn (int $assetId): \Throwable => new StaleApplyPlanException(sprintf('Asset %d is being processed. Preview the operation again.', $assetId)),
            function (array $lockedIds) use ($expectedFingerprints, $operation): mixed {
                foreach ($lockedIds as $assetId) {
                    $this->mutationFingerprints->assertUnchanged($assetId, $expectedFingerprints);
                }

                return $operation($lockedIds);
            },
        );
    }
}
