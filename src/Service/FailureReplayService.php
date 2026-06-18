<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Audit\AuditLoggerInterface;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Message\OrganizeAssetsMessage;
use Oronts\AssetPilotBundle\Model\ReplayResult;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

/**
 * Re-runs the objects whose organization failed, read from the audit log. Re-organizing the object
 * is idempotent (already-at-target assets skip, LoopGuard prevents loops), so replaying a partial
 * failure is safe. The candidate set is bounded and grouped by object so a flood of failures does
 * not turn into an unbounded scan or N redundant re-organizes.
 */
class FailureReplayService
{
    public function __construct(
        protected readonly AuditLoggerInterface $auditLogger,
        protected readonly AssetOrganizer $organizer,
        protected readonly MessageBusInterface $messageBus,
        protected readonly LoggerInterface $logger,
        protected readonly int $defaultLimit = 100,
    ) {}

    /**
     * @param array{since?: string, rule_name?: string, object_class?: string} $filters
     */
    public function replay(array $filters = [], bool $async = false, ?int $limit = null): ReplayResult
    {
        $candidates = $this->auditLogger->getDistinctFailedObjects($filters, $limit ?? $this->defaultLimit);
        $objectIds = array_map(static fn (array $row): int => (int) ($row['object_id'] ?? 0), $candidates);

        return $this->replayObjects($objectIds, $async);
    }

    /**
     * Re-organize an explicit set of object ids (the targeted counterpart to replay(), for "re-run
     * just this object" rather than every failed object in the audit log). Same idempotent,
     * LoopGuard-safe organize as replay().
     *
     * @param int[] $objectIds
     */
    public function replayObjects(array $objectIds, bool $async = false): ReplayResult
    {
        // Distinct ids only: re-organizing one object twice is wasted work (the audit-driven path is
        // already distinct; this also de-dupes an explicit --object-id=42,42).
        $objectIds = array_values(array_unique(array_map('intval', $objectIds)));

        $organized = 0;
        $dispatched = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($objectIds as $objectId) {
            if ($objectId <= 0) {
                ++$skipped;
                continue;
            }

            if ($async) {
                $this->dispatch($objectId);
                ++$dispatched;
                continue;
            }

            $object = $this->loadObject($objectId);
            if ($object === null) {
                ++$skipped;
                continue;
            }

            try {
                $results = $this->organizer->organize($object, TriggerType::Manual);
            } catch (\Throwable $e) {
                $this->logger->error('Asset Pilot: replay re-organize failed for object {id}: {error}', [
                    'id' => $objectId,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
                ++$failed;
                continue;
            }

            // organize() reports per-asset move failures as Failed results, not exceptions, so a
            // re-organize that "ran" can still have failed moves; count the object as failed then.
            $objectFailed = false;
            foreach ($results as $result) {
                if ($result->status === OperationStatus::Failed) {
                    $objectFailed = true;
                    break;
                }
            }
            $objectFailed ? ++$failed : ++$organized;
        }

        return new ReplayResult(count($objectIds), $organized, $dispatched, $skipped, $failed);
    }

    protected function dispatch(int $objectId): void
    {
        $this->messageBus->dispatch(Envelope::wrap(
            new OrganizeAssetsMessage($objectId, TriggerType::Manual, time()),
            [new DeduplicateStamp('asset_pilot_organize_' . $objectId, 30.0)],
        ));
    }

    protected function loadObject(int $objectId): ?AbstractObject
    {
        return AbstractObject::getById($objectId);
    }
}
