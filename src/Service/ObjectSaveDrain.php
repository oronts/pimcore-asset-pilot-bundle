<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Psr\Log\LoggerInterface;

final class ObjectSaveDrain implements ObjectSaveDrainInterface
{
    public function __construct(
        private readonly LoopGuard $loopGuard,
        private readonly OrganizeDispatcherInterface $dispatcher,
        private readonly AutomaticOrganizeIntentStoreInterface $intents,
        private readonly LoggerInterface $logger,
    ) {}

    public function drain(int $objectId, TriggerType $trigger, ActorContext $actor, ?string $runId = null): void
    {
        try {
            $intentDirty = $runId !== null && $this->intents->releaseIfOwnedBy($objectId, $runId);
            $this->loopGuard->clearObjectDispatched($objectId);
            if (!$this->loopGuard->isObjectDirty($objectId) && !$intentDirty) {
                return;
            }
            $this->dispatcher->dispatchObject($objectId, $trigger, $actor);
            $this->loopGuard->clearObjectDirty($objectId);
        } catch (\Throwable $e) {
            try {
                $this->logger->error('Asset Pilot: could not queue an organize to drain a coalesced save for object {id}: {error}', [
                    'id' => $objectId,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            } catch (\Throwable) {
                // A broken logger must not defeat the best-effort, never-throw contract.
            }
        }
    }
}
