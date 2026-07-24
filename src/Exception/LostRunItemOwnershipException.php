<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Exception;

/**
 * Thrown when a token-fenced run-item completion reports that this attempt no longer owns the durable
 * claim (a concurrent reclaim or terminalization won). The mutation may already be applied to Pimcore,
 * but the operation ledger did not accept our completion, so the caller must never finish the run,
 * return success, or acknowledge the message: it retries (Messenger) or reports a conflict (sync) and
 * lets the owning attempt reconcile the item.
 */
class LostRunItemOwnershipException extends \RuntimeException
{
    public static function forItem(string $runId, string $itemKey): self
    {
        return new self(sprintf('Run item %s of run %s was completed by another attempt; not reporting durable completion.', $itemKey, $runId));
    }
}
