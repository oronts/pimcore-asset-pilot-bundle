<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Maintenance;

use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Message\BulkOrganizeMessage;
use Oronts\AssetPilotBundle\Message\OrganizeAssetsMessage;
use Oronts\AssetPilotBundle\Service\AutomaticOrganizeIntentStoreInterface;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcherInterface;
use Pimcore\Maintenance\TaskInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Producer outbox relay. Automatic (listener) organize producers commit the run as `pending_dispatch`
 * atomically with the (possibly consumer-owned) source transaction and do not touch the broker. This relay
 * publishes only the COMMITTED pending runs and transitions each to Queued, which closes the pre-commit
 * publish race: a
 * rolled-back save leaves no run and no phantom message; a fast worker never sees a run before its
 * transaction commits; a broker outage leaves the run pending for the next pass without ever failing the
 * already-committed save; a crash after publish re-publishes and the worker's token-fenced run item makes
 * that idempotent.
 */
final class OrganizeDispatchRelayTask implements TaskInterface
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly OperationRunStoreInterface $runs,
        private readonly AutomaticOrganizeIntentStoreInterface $intents,
        private readonly OrganizeDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
        private readonly int $batchSize,
    ) {}

    public function execute(): void
    {
        $this->reclaimStaleIntents();
        $published = 0;
        $failed = 0;
        foreach ($this->runs->dueForDispatch($this->batchSize) as $run) {
            $message = $this->buildMessage($run);
            if ($message === null) {
                // Undispatchable runs can never be published; fail them so they are visible, not pending forever.
                $this->runs->fail($run['id'], 'Undispatchable pending organize run: unrecognized trigger/actor or no targets.');
                ++$failed;

                continue;
            }
            try {
                $this->messageBus->dispatch(Envelope::wrap($message));
            } catch (\Throwable $e) {
                ++$failed;
                $this->logger->error('Asset Pilot: could not publish pending organize run {run}, leaving it pending for the next relay pass: {error}', [
                    'run' => $run['id'],
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);

                continue;
            }
            $this->runs->markDispatched($run['id']);
            ++$published;
        }

        if ($published > 0 || $failed > 0) {
            $this->logger->info('Asset Pilot: published {published} pending organize runs; {failed} still pending.', [
                'published' => $published,
                'failed' => $failed,
            ]);
        }
    }

    /**
     * Reclaim automatic-organize intents whose bound run finished or was purged but whose normal terminal path
     * missed the release (undispatchable, exhausted delivery, or a crash). A dirty intent means a save coalesced
     * into a run that never organized it, so re-dispatch its latest state; a clean one is just released.
     */
    private function reclaimStaleIntents(): void
    {
        foreach ($this->intents->staleIntents($this->batchSize) as $intent) {
            try {
                if ($this->intents->releaseIfOwnedBy($intent->objectId, $intent->runId)) {
                    $this->dispatcher->dispatchObject($intent->objectId, $intent->trigger, $intent->actor);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Asset Pilot: could not reclaim a stale automatic-organize intent for object {id}: {error}', [
                    'id' => $intent->objectId,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * @param array{id: string, actorType: string, actorUserId: int|null, trigger: string, targets: list<array{id: int, fingerprint: string|null}>} $run
     */
    private function buildMessage(array $run): OrganizeAssetsMessage|BulkOrganizeMessage|null
    {
        $trigger = TriggerType::tryFrom($run['trigger']);
        $actorType = ActorType::tryFrom($run['actorType']);
        if ($trigger === null || $actorType === null || $run['targets'] === []) {
            $this->logger->error('Asset Pilot: skipping pending organize run {run} with an unrecognized trigger/actor or no targets.', [
                'run' => $run['id'],
            ]);

            return null;
        }

        $now = time();
        if (count($run['targets']) === 1) {
            $target = $run['targets'][0];

            return new OrganizeAssetsMessage($target['id'], $trigger, $now, $actorType, $run['actorUserId'], $run['id'], $target['fingerprint']);
        }

        $objectIds = [];
        $fingerprints = [];
        foreach ($run['targets'] as $target) {
            $objectIds[] = $target['id'];
            if ($target['fingerprint'] !== null) {
                $fingerprints[$target['id']] = $target['fingerprint'];
            }
        }

        return new BulkOrganizeMessage($objectIds, $trigger, $now, $actorType, $run['actorUserId'], $run['id'], $fingerprints);
    }
}
