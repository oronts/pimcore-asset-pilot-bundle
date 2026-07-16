<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\OperationKind;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Model\OperationHandle;
use Oronts\AssetPilotBundle\Model\OperationIntent;
use Oronts\AssetPilotBundle\Model\OperationRecoveryResult;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Strategy\FirstAssignmentStrategy;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;

class OperationRecoveryService
{
    public function __construct(
        private readonly OperationJournalInterface $journal,
        private readonly LoopGuard $loopGuard,
        private readonly ElementAuthorization $authorization,
        private readonly LoggerInterface $logger,
        private readonly int $recoveryAfterSeconds,
    ) {
        if ($recoveryAfterSeconds <= 0) {
            throw new \InvalidArgumentException('The operation recovery interval must be positive.');
        }
    }

    /** @return list<OperationRecoveryResult> */
    public function preview(int $limit = 100): array
    {
        return $this->reconcile($limit, false);
    }

    /**
     * @param list<int>|null $operationIds
     * @return list<OperationRecoveryResult>
     */
    public function recover(int $limit = 100, ?array $operationIds = null): array
    {
        if ($operationIds !== null && (count($operationIds) !== count(array_unique($operationIds)) || array_any($operationIds, static fn (int $id): bool => $id <= 0))) {
            throw new \InvalidArgumentException('Reviewed recovery operation IDs must be unique and positive.');
        }

        return $this->reconcile($limit, true, $operationIds);
    }

    /** @return list<OperationRecoveryResult> */
    private function reconcile(int $limit, bool $updateJournal, ?array $operationIds = null): array
    {
        $operations = $this->journal->recoverable($limit, $this->recoveryAfterSeconds);
        if ($operationIds !== null) {
            $reviewed = array_fill_keys($operationIds, true);
            $operations = array_values(array_filter(
                $operations,
                static fn (OperationHandle $operation): bool => isset($reviewed[$operation->operationId]),
            ));
        }

        return array_map(
            fn (OperationHandle $operation): OperationRecoveryResult => $this->reconcileOperation($operation, $updateJournal),
            $operations,
        );
    }

    private function reconcileOperation(OperationHandle $operation, bool $updateJournal): OperationRecoveryResult
    {
        $intent = $operation->intent;
        if (!$this->loopGuard->acquireAsset($intent->assetId)) {
            return $this->result(
                $operation,
                OperationStatus::RecoveryRequired,
                false,
                'The operation asset is busy; recovery was not applied.',
            );
        }

        try {
            [$status, $message] = $this->classify($operation);
            if (!$updateJournal) {
                return $this->result($operation, $status, false, $message);
            }

            return $this->updateJournal($operation, $status, $message);
        } finally {
            $this->loopGuard->releaseAsset($intent->assetId);
        }
    }

    /** @return array{OperationStatus, string} */
    private function classify(OperationHandle $operation): array
    {
        $intent = $operation->intent;

        try {
            $asset = $this->loadAsset($intent->assetId);
            if ($asset === null || $asset instanceof Asset\Folder) {
                return [OperationStatus::RecoveryRequired, 'The operation asset is unavailable.'];
            }
            if (!$this->authorization->isAllowed($asset, 'publish', $intent->actor)) {
                return [OperationStatus::RecoveryRequired, 'The initiating actor is no longer permitted to publish the operation asset.'];
            }

            return match ($intent->kind) {
                OperationKind::Move => $this->classifyMove($asset, $intent),
                OperationKind::Revert => $this->classifyRevert($asset, $intent),
            };
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to inspect a persisted operation during recovery.', [
                'operation_id' => $operation->operationId,
                'asset_id' => $intent->assetId,
                'operation_kind' => $intent->kind->value,
                'actor_type' => $intent->actor->type->value,
                'actor_user_id' => $intent->actor->userId,
                'exception' => $e,
            ]);

            return [OperationStatus::RecoveryRequired, 'The persisted asset state could not be inspected.'];
        }
    }

    /** @return array{OperationStatus, string} */
    private function classifyMove(Asset $asset, OperationIntent $intent): array
    {
        if ($intent->sourcePath === $intent->targetPath) {
            return [OperationStatus::RecoveryRequired, 'The move intent does not have distinct source and target paths.'];
        }

        $firstAssignment = $intent->context['firstAssignment'] ?? false;
        if (!is_bool($firstAssignment)) {
            return [OperationStatus::RecoveryRequired, 'The move intent has an invalid first-assignment postcondition.'];
        }

        $path = $asset->getRealFullPath();
        $isAssigned = $firstAssignment && $asset->getProperty(FirstAssignmentStrategy::ASSIGNMENT_PROPERTY) === true;
        if ($path === $intent->targetPath && (!$firstAssignment || $isAssigned)) {
            return [OperationStatus::Completed, 'The persisted move postcondition is satisfied.'];
        }
        if ($path === $intent->sourcePath && (!$firstAssignment || !$isAssigned)) {
            return [OperationStatus::Failed, 'The persisted move did not commit.'];
        }

        return [OperationStatus::RecoveryRequired, 'The persisted move state does not match a known postcondition.'];
    }

    /** @return array{OperationStatus, string} */
    private function classifyRevert(Asset $asset, OperationIntent $intent): array
    {
        if ($intent->sourcePath === $intent->targetPath) {
            return [OperationStatus::RecoveryRequired, 'The revert intent does not have distinct source and target paths.'];
        }

        $path = $asset->getRealFullPath();
        if ($path === $intent->targetPath) {
            return [OperationStatus::Completed, 'The persisted revert postcondition is satisfied.'];
        }
        if ($path === $intent->sourcePath) {
            return [OperationStatus::Failed, 'The persisted revert did not commit.'];
        }

        return [OperationStatus::RecoveryRequired, 'The persisted revert state does not match a known postcondition.'];
    }

    private function updateJournal(
        OperationHandle $operation,
        OperationStatus $status,
        string $message,
    ): OperationRecoveryResult {
        try {
            $this->loopGuard->refreshAsset($operation->intent->assetId);
            $updated = $this->journal->complete(
                $operation,
                $status,
                $status === OperationStatus::Completed ? null : $message,
            );

            return $this->result($operation, $status, $updated, $message);
        } catch (\Throwable $e) {
            $this->logger->critical('Asset Pilot: a classified operation could not be finalized during recovery.', [
                'operation_id' => $operation->operationId,
                'asset_id' => $operation->intent->assetId,
                'classified_status' => $status->value,
                'exception' => $e,
            ]);

            return $this->result(
                $operation,
                $status,
                false,
                $message . ' The operation journal could not be updated.',
            );
        }
    }

    private function result(
        OperationHandle $operation,
        OperationStatus $status,
        bool $journalUpdated,
        string $message,
    ): OperationRecoveryResult {
        return new OperationRecoveryResult(
            $operation->operationId,
            $operation->intent->assetId,
            $operation->intent->kind,
            $status,
            $journalUpdated,
            $message,
            hash('sha256', json_encode([
                'intent' => $operation->intent->toArray(),
                'classification' => $status->value,
                'message' => $message,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        );
    }

    protected function loadAsset(int $assetId): ?Asset
    {
        return Asset::getById($assetId, ['force' => true]);
    }
}
