<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\BulkOrganizeReport;
use Oronts\AssetPilotBundle\Model\DriftItem;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Model\OperationResult;
use Pimcore\Model\DataObject\AbstractObject;

interface AssetOrganizerInterface
{
    /** @return list<OperationResult> */
    public function organize(
        AbstractObject $object,
        TriggerType $triggerType = TriggerType::ObjectSave,
        ?string $ruleName = null,
        ?string $expectedFingerprint = null,
    ): array;

    /** @return list<OperationResult> */
    public function organizeWithHeartbeat(
        AbstractObject $object,
        TriggerType $triggerType,
        callable $heartbeat,
        ?string $ruleName = null,
        ?string $expectedFingerprint = null,
    ): array;

    /** @return list<MoveOperation> */
    public function dryRun(AbstractObject $object, TriggerType $triggerType = TriggerType::Manual, ?string $ruleName = null): array;

    /** @param list<MoveOperation> $operations */
    public function preflightApply(array $operations): ?string;

    /** @return list<DriftItem> */
    public function analyzeDrift(AbstractObject $object, ?string $ruleName = null): array;

    /** @param list<int> $objectIds @return list<OperationResult> */
    public function organizeBulk(array $objectIds, TriggerType $triggerType, ?callable $progressCallback = null, ?int $dispatchedAt = null): array;

    /** @param list<int> $objectIds @param array<int, string> $expectedFingerprints */
    public function organizeBulkDetailed(
        array $objectIds,
        TriggerType $triggerType,
        ?callable $progressCallback = null,
        ?int $dispatchedAt = null,
        ?callable $staleCallback = null,
        ?callable $shouldCancel = null,
        ?callable $beforeObject = null,
        array $expectedFingerprints = [],
        ?callable $heartbeat = null,
        ?callable $afterObject = null,
    ): BulkOrganizeReport;
}
