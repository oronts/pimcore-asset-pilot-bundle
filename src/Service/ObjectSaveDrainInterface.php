<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\ActorContext;

/**
 * The single owner of the coalesced-save drain ceremony shared by every guarded-DataObject-save boundary
 * (async organize handlers, synchronous reviewed executors, and duplicate repointing). A save that lands
 * while an organize holds an object marks it dirty and records no run of its own, trusting the terminal path
 * to drain it; each terminal path must therefore release the dispatch-coalescing marker and queue one fresh
 * non-fingerprinted organize under the initiating actor. Centralizing it keeps that ordering identical across
 * modes instead of duplicated per call site.
 */
interface ObjectSaveDrainInterface
{
    /**
     * Release the object's dispatch-coalescing marker, then, if a concurrent save marked it dirty, queue a
     * fresh non-fingerprinted organize under $actor for its latest state and clear the dirty flag. A no-op when
     * the object is not dirty. Best-effort: a dispatch failure is logged and never propagated, so a terminal
     * caller is never turned into a retry loop and a later save still recovers the object.
     */
    public function drain(int $objectId, TriggerType $trigger, ActorContext $actor): void;
}
