<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;

class RunItemLease
{
    public function __construct(
        private readonly LoopGuard $loopGuard,
        private readonly OperationRunStoreInterface $runs,
    ) {}

    public function begin(string $runId, string $itemKey): string
    {
        return $this->loopGuard->beginOperationRunItemLease($runId, $itemKey);
    }

    public function start(string $runId, string $itemKey): bool
    {
        $token = $this->begin($runId, $itemKey);
        try {
            if (!$this->runs->startItem($runId, $itemKey, $token)) {
                $this->release($runId, $itemKey);

                return false;
            }
        } catch (\Throwable $e) {
            // The durable claim never landed; release the minted token so it cannot be mistaken for ownership.
            $this->release($runId, $itemKey);

            throw $e;
        }

        return true;
    }

    /**
     * Fence the terminal completion with the owned claim token and release the local lock. Returns false
     * when this owner no longer held the durable claim (a concurrent reclaim or terminalization won), so a
     * caller must never report the mutation as durably recorded.
     *
     * @param array<string, mixed> $result
     */
    public function complete(string $runId, string $itemKey, OperationRunItemStatus $status, array $result = [], ?string $error = null): bool
    {
        $completed = $this->runs->completeItem($runId, $itemKey, $status, $result, $error, $this->token($runId, $itemKey));
        $this->release($runId, $itemKey);

        return $completed;
    }

    public function token(string $runId, string $itemKey): ?string
    {
        return $this->loopGuard->operationRunItemToken($runId, $itemKey);
    }

    public function pulse(string $runId, string $itemKey): void
    {
        $token = $this->loopGuard->operationRunItemToken($runId, $itemKey);
        if ($token !== null && !$this->runs->renewItemLease($runId, $itemKey, $token)) {
            throw new \RuntimeException('The operation run item lease was lost; aborting to avoid a double mutation.');
        }
    }

    public function release(string $runId, string $itemKey): void
    {
        $this->loopGuard->releaseOperationRunItem($runId, $itemKey);
    }
}
