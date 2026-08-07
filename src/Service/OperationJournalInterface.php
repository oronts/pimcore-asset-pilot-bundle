<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\ObserverAuditReconciliationStatus;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Model\OperationHandle;
use Oronts\AssetPilotBundle\Model\OperationIntent;

interface OperationJournalInterface
{
    public function begin(OperationIntent $intent): OperationHandle;

    public function complete(
        OperationHandle $operation,
        OperationStatus $status,
        ?string $errorMessage = null,
        ?int $durationMs = null,
    ): bool;

    /** @return list<OperationHandle> */
    public function recoverable(int $limit = 100, int $staleSeconds = 900): array;

    public function recordObserverFailure(int $operationId, string $observerId): ObserverAuditReconciliationStatus;

    public function resolveObserverFailures(int $operationId): ObserverAuditReconciliationStatus;
}
