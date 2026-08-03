<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\AutomaticOrganizeIntent;
use Oronts\AssetPilotBundle\Model\AutomaticOrganizeIntentBinding;

interface AutomaticOrganizeIntentStoreInterface
{
    /**
     * Atomically bind one durable automatic-organize intent to the object inside the caller's transaction.
     * The winner (isNew) owns $candidateRunId and creates the run; a concurrent save coalesces into the
     * existing intent (marked dirty) and gets its run id back without creating a second run.
     */
    public function bindOrCoalesce(int $objectId, string $candidateRunId, TriggerType $trigger, ActorContext $actor): AutomaticOrganizeIntentBinding;

    /**
     * Release the object's intent only when it is still bound to $runId (its own run's terminal, or the
     * maintenance backstop), so a concurrent run draining the same object cannot drop a live pending intent.
     * Returns true when a later save marked it dirty, so the caller must re-organize the object.
     */
    public function releaseIfOwnedBy(int $objectId, string $runId): bool;

    /**
     * Intents whose bound run has reached a terminal state or no longer exists, so the maintenance backstop
     * can reclaim a release the normal terminal paths missed (rotating any that are dirty).
     *
     * @return list<AutomaticOrganizeIntent>
     */
    public function staleIntents(int $limit): array;
}
