<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

/**
 * Deliberately final (asserted by ExtensionContractTest): a consumer must not subclass and weaken the
 * reviewed-lock enforcement that gates every destructive selection workflow. This is the one intentional
 * exception to the bundle's otherwise-extensible, non-final class policy.
 */
final class ReviewedAssetLockCoordinator
{
    public function __construct(private readonly LoopGuard $loopGuard) {}

    /**
     * @template TResult
     * @param list<int> $assetIds
     * @param callable(int): \Throwable $busyException
     * @param callable(list<int>): TResult $operation
     * @return TResult
     */
    public function run(array $assetIds, callable $busyException, callable $operation): mixed
    {
        $assetIds = $this->normalize($assetIds);
        $acquired = [];

        try {
            foreach ($assetIds as $assetId) {
                if (!$this->loopGuard->acquireAsset($assetId)) {
                    throw $busyException($assetId);
                }
                $acquired[] = $assetId;
            }

            return $operation($assetIds);
        } finally {
            foreach (array_reverse($acquired) as $assetId) {
                $this->loopGuard->releaseAsset($assetId);
            }
        }
    }

    /** @param list<int> $assetIds */
    public function refresh(array $assetIds): void
    {
        foreach ($assetIds as $assetId) {
            $this->loopGuard->refreshAsset($assetId);
        }
    }

    /** @param list<int> $assetIds @return list<int> */
    private function normalize(array $assetIds): array
    {
        $assetIds = array_values(array_unique(array_map('intval', $assetIds)));
        foreach ($assetIds as $assetId) {
            if ($assetId <= 0) {
                throw new \InvalidArgumentException('Reviewed asset locks require positive element IDs.');
            }
        }
        sort($assetIds, SORT_NUMERIC);

        return $assetIds;
    }
}
