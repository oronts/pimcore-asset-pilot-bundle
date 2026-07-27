<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\OperationRunKind;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\ActorContext;

interface OrganizeDispatcherInterface
{
    public function dispatchObject(
        int $objectId,
        TriggerType $triggerType,
        ?ActorContext $actor = null,
        ?string $runId = null,
        ?string $expectedFingerprint = null,
    ): string;

    /** @param list<int> $objectIds @param array<int, string> $expectedFingerprints */
    public function dispatchBulk(
        array $objectIds,
        TriggerType $triggerType,
        ?ActorContext $actor = null,
        ?string $runId = null,
        array $expectedFingerprints = [],
    ): string;

    /** @param list<int> $objectIds @param array<int, string> $expectedFingerprints */
    public function createRun(
        array $objectIds,
        TriggerType $triggerType,
        ?ActorContext $actor = null,
        array $expectedFingerprints = [],
        OperationRunKind $kind = OperationRunKind::Organize,
        array $request = [],
        OperationRunStatus $initialStatus = OperationRunStatus::Queued,
    ): string;

    public function deferObject(int $objectId, TriggerType $triggerType, ?ActorContext $actor = null, ?string $expectedFingerprint = null): string;

    /** @param int[] $objectIds @param array<int, string> $expectedFingerprints */
    public function deferBulk(array $objectIds, TriggerType $triggerType, ?ActorContext $actor = null, array $expectedFingerprints = []): string;
}
