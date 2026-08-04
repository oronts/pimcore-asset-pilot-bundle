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
     * Release the object's dispatch-coalescing marker and, when $runId is given, its durable automatic-organize
     * intent bound to that run; then, if either the cache marker or the intent recorded a coalesced save, queue
     * one fresh non-fingerprinted organize under $actor for the latest state and clear the dirty flag. A no-op
     * when nothing coalesced. Best-effort: a dispatch failure is logged and never propagated, so a terminal
     * caller is never turned into a retry loop and a later save still recovers the object.
     */
    public function drain(int $objectId, TriggerType $trigger, ActorContext $actor, ?string $runId = null): void;

    /**
     * Guarantee exactly one replacement organize for a message found stale (its object changed after dispatch):
     * release this run's intent so the backstop does not also rotate it, then record one fresh non-fingerprinted
     * organize of the latest state, coalescing into any newer pending run. Unlike drain(), this is unconditional
     * because the stale check already proved the object changed, so it never silently drops the replacement.
     * Best-effort/never-throw: a failure rolls the release back, leaving the intent for the maintenance backstop.
     */
    public function rotateStaleReplacement(int $objectId, TriggerType $trigger, ActorContext $actor, ?string $runId): void;
}
