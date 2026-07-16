<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Message\BulkOrganizeMessage;
use Oronts\AssetPilotBundle\Message\OrganizeAssetsMessage;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Security\ActorContextStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class OrganizeDispatcher
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ActorContextStore $actors,
        private readonly OperationRunStoreInterface $runs,
    ) {}

    public function dispatchObject(
        int $objectId,
        TriggerType $triggerType,
        ?ActorContext $actor = null,
        ?string $runId = null,
        ?string $expectedFingerprint = null,
    ): string {
        $actor ??= $this->actors->current();
        $runId ??= $this->createRun(
            [$objectId],
            $triggerType,
            $actor,
            $expectedFingerprint === null ? [] : [$objectId => $expectedFingerprint],
        );
        $this->messageBus->dispatch(Envelope::wrap(
            new OrganizeAssetsMessage($objectId, $triggerType, $this->now(), $actor->type, $actor->userId, $runId, $expectedFingerprint),
        ));

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
        $actor ??= $this->actors->current();
        $runId ??= $this->createRun($objectIds, $triggerType, $actor, $expectedFingerprints);

        $this->messageBus->dispatch(Envelope::wrap(
            new BulkOrganizeMessage($objectIds, $triggerType, $this->now(), $actor->type, $actor->userId, $runId, $expectedFingerprints),
        ));

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
        string $kind = 'organize',
        array $request = [],
    ): string {
        if ($objectIds === []) {
            throw new \InvalidArgumentException('An organize run requires at least one object ID.');
        }
        if ($kind === '') {
            throw new \InvalidArgumentException('An organize run requires a kind.');
        }

        $actor ??= $this->actors->current();

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
        );
    }

    protected function now(): int
    {
        return time();
    }
}
