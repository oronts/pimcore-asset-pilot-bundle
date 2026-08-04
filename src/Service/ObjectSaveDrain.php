<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Psr\Log\LoggerInterface;

final class ObjectSaveDrain implements ObjectSaveDrainInterface
{
    public function __construct(
        private readonly LoopGuard $loopGuard,
        private readonly OrganizeDispatcherInterface $dispatcher,
        private readonly AutomaticOrganizeIntentStoreInterface $intents,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {}

    public function drain(int $objectId, TriggerType $trigger, ActorContext $actor, ?string $runId = null): void
    {
        try {
            if ($runId !== null) {
                // Release this run's intent and re-organize a coalesced save atomically, so a failure leaves
                // either the intent (backstop reclaims) or a durable fresh run, never an unrecoverable save.
                $this->connection->transactional(function () use ($objectId, $trigger, $actor, $runId): void {
                    if ($this->intents->releaseIfOwnedBy($objectId, $runId)) {
                        $this->dispatcher->deferObject($objectId, $trigger, $actor);
                    }
                });
            }
            $this->loopGuard->clearObjectDispatched($objectId);
            if ($this->loopGuard->isObjectDirty($objectId)) {
                $this->dispatcher->dispatchObject($objectId, $trigger, $actor);
                $this->loopGuard->clearObjectDirty($objectId);
            }
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
