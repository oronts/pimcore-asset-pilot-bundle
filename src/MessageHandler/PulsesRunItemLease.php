<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\MessageHandler;

/**
 * Shared run-item lease heartbeat for the organize handlers: refresh the loop-guard item and renew the
 * durable lease under the held token, aborting if the lease was lost so a redelivery cannot double-execute.
 * The using handler must expose the loop guard as `$this->loopGuard` and the run store as `$this->runs`
 * (both organize handlers do).
 */
trait PulsesRunItemLease
{
    private function pulseRunItemLease(string $runId, string $itemKey): void
    {
        $this->loopGuard->refreshOperationRunItem($runId, $itemKey);
        $token = $this->loopGuard->operationRunItemToken($runId, $itemKey);
        if ($token !== null && !$this->runs->renewItemLease($runId, $itemKey, $token)) {
            throw new \RuntimeException(sprintf('Lost the durable lease on operation run item "%s"; aborting to prevent a double execution.', $itemKey));
        }
    }

    private function itemKey(int $objectId): string
    {
        return 'object:' . $objectId;
    }
}
