<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Enum\OperationRunKind;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Message\BulkOrganizeMessage;
use Oronts\AssetPilotBundle\Message\OrganizeAssetsMessage;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class OrganizeDispatcher implements OrganizeDispatcherInterface
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ElementAuthorizationInterface $authorization,
        private readonly OperationRunStoreInterface $runs,
        private readonly AutomaticOrganizeIntentStoreInterface $intents,
        private readonly Connection $connection,
    ) {}

    public function dispatchObject(
        int $objectId,
        TriggerType $triggerType,
        ?ActorContext $actor = null,
        ?string $runId = null,
        ?string $expectedFingerprint = null,
    ): string {
        $actor ??= $this->authorization->currentActor();
        $ownsRun = $runId === null;
        if ($ownsRun) {
            $runId = $this->createRun(
                [$objectId],
                $triggerType,
                $actor,
                $expectedFingerprint === null ? [] : [$objectId => $expectedFingerprint],
            );
        }
        try {
            $this->messageBus->dispatch(Envelope::wrap(
                new OrganizeAssetsMessage($objectId, $triggerType, $this->now(), $actor->type, $actor->userId, $runId, $expectedFingerprint),
            ));
        } catch (\Throwable $exception) {
            $this->failOwnedRun($runId, $ownsRun);
            throw $exception;
        }

        return $runId;
    }

    /**
     * @param int[]              $objectIds
     * @param array<int, string> $expectedFingerprints
     */
    public function dispatchBulk(
        array $objectIds,
        TriggerType $triggerType,
        ?ActorContext $actor = null,
        ?string $runId = null,
        array $expectedFingerprints = [],
    ): string {
        $objectIds = array_values($objectIds);
        sort($objectIds);
        $actor ??= $this->authorization->currentActor();
        $ownsRun = $runId === null;
        if ($ownsRun) {
            $runId = $this->createRun($objectIds, $triggerType, $actor, $expectedFingerprints);
        }
        try {
            $this->messageBus->dispatch(Envelope::wrap(
                new BulkOrganizeMessage($objectIds, $triggerType, $this->now(), $actor->type, $actor->userId, $runId, $expectedFingerprints),
            ));
        } catch (\Throwable $exception) {
            $this->failOwnedRun($runId, $ownsRun);
            throw $exception;
        }

        return $runId;
    }

    /**
     * @param list<int>          $objectIds
     * @param array<int, string> $expectedFingerprints
     */
    public function createRun(
        array $objectIds,
        TriggerType $triggerType,
        ?ActorContext $actor = null,
        array $expectedFingerprints = [],
        OperationRunKind $kind = OperationRunKind::Organize,
        array $request = [],
        OperationRunStatus $initialStatus = OperationRunStatus::Queued,
        ?string $runId = null,
    ): string {
        if ($objectIds === []) {
            throw new \InvalidArgumentException('An organize run requires at least one object ID.');
        }
        $actor ??= $this->authorization->currentActor();

        return $this->runs->create(
            $kind,
            $actor,
            array_map(static fn (int $objectId): array => [
                'key' => 'object:' . $objectId,
                'type' => 'data_object',
                'id' => $objectId,
                'fingerprint' => $expectedFingerprints[$objectId] ?? null,
                'payload' => ['trigger' => $triggerType->value],
            ], array_values(array_unique($objectIds))),
            ['trigger' => $triggerType->value, ...$request],
            null,
            $initialStatus,
            $runId,
        );
    }

    /**
     * Automatic (listener) producers create the run as `PendingDispatch` inside the same (possibly
     * consumer-owned) transaction as the source save and DO NOT publish. The dispatch relay publishes
     * only committed pending runs, so a rolled-back save leaves no run and no phantom message, and a fast
     * worker never sees a run before its transaction commits. Manual/controller producers keep the direct
     * dispatchObject/dispatchBulk path (they run outside a source save transaction).
     */
    public function deferObject(int $objectId, TriggerType $triggerType, ?ActorContext $actor = null, ?string $expectedFingerprint = null): string
    {
        $actor ??= $this->authorization->currentActor();

        // Bind the intent and record its pending run atomically so the relay never sees a committed intent
        // whose run does not exist yet (and reclaim it), and a rolled-back reservation orphans neither.
        return $this->connection->transactional(function () use ($objectId, $triggerType, $actor, $expectedFingerprint): string {
            $candidateRunId = bin2hex(random_bytes(16));
            $binding = $this->intents->bindOrCoalesce($objectId, $candidateRunId, $triggerType, $actor);
            if (!$binding->isNew) {
                return $binding->runId;
            }

            return $this->createRun(
                [$objectId],
                $triggerType,
                $actor,
                $expectedFingerprint === null ? [] : [$objectId => $expectedFingerprint],
                initialStatus: OperationRunStatus::PendingDispatch,
                runId: $candidateRunId,
            );
        });
    }

    private function failOwnedRun(string $runId, bool $ownsRun): void
    {
        if ($ownsRun) {
            $this->runs->fail($runId, 'The organize operation could not be dispatched.');
        }
    }

    protected function now(): int
    {
        return time();
    }
}
