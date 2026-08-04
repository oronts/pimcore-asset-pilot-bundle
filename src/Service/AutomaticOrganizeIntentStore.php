<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\AutomaticOrganizeIntent;
use Oronts\AssetPilotBundle\Model\AutomaticOrganizeIntentBinding;

class AutomaticOrganizeIntentStore implements AutomaticOrganizeIntentStoreInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function bindOrCoalesce(int $objectId, string $candidateRunId, TriggerType $trigger, ActorContext $actor): AutomaticOrganizeIntentBinding
    {
        $now = $this->now();
        $upsert = $this->connection->getDatabasePlatform() instanceof SQLitePlatform
            ? 'ON CONFLICT(id) DO UPDATE SET dirty = 1, updated_at = :touched'
            : 'ON DUPLICATE KEY UPDATE dirty = 1, updated_at = :touched';
        $this->connection->executeStatement(
            'INSERT INTO ' . Installer::TABLE_AUTOMATIC_ORGANIZE_INTENT
            . ' (id, run_id, trigger_type, actor_type, actor_user_id, dirty, created_at, updated_at)'
            . ' VALUES (:id, :run_id, :trigger, :actor_type, :actor_user_id, 0, :now, :now) ' . $upsert,
            [
                'id' => $objectId,
                'run_id' => $candidateRunId,
                'trigger' => $trigger->value,
                'actor_type' => $actor->type->value,
                'actor_user_id' => $actor->userId,
                'now' => $now,
                'touched' => $now,
            ],
            ['actor_user_id' => ParameterType::INTEGER],
        );
        $selected = (string) $this->connection->fetchOne(
            'SELECT run_id FROM ' . Installer::TABLE_AUTOMATIC_ORGANIZE_INTENT . ' WHERE id = ?',
            [$objectId],
        );

        return new AutomaticOrganizeIntentBinding($selected, $selected === $candidateRunId);
    }

    public function releaseIfOwnedBy(int $objectId, string $runId): bool
    {
        // Release a clean intent in one atomic conditional DELETE. A concurrent coalescing save can only
        // flip dirty 0 -> 1, so if the guarded delete matched nothing the row is either gone, owned by a
        // newer run, or dirty: a second delete that matches only the dirty row of THIS run takes ownership
        // of the rotation without a row lock.
        if ($this->connection->delete(Installer::TABLE_AUTOMATIC_ORGANIZE_INTENT, ['id' => $objectId, 'run_id' => $runId, 'dirty' => 0]) === 1) {
            return false;
        }

        return $this->connection->delete(Installer::TABLE_AUTOMATIC_ORGANIZE_INTENT, ['id' => $objectId, 'run_id' => $runId, 'dirty' => 1]) === 1;
    }

    public function markDirtyIfPresent(int $objectId): void
    {
        $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_AUTOMATIC_ORGANIZE_INTENT . ' SET dirty = 1, updated_at = :now WHERE id = :id',
            ['now' => $this->now(), 'id' => $objectId],
        );
    }

    /** @return list<AutomaticOrganizeIntent> */
    public function staleIntents(int $limit): array
    {
        $terminal = array_values(array_map(
            static fn (OperationRunStatus $status): string => $status->value,
            array_filter(OperationRunStatus::cases(), static fn (OperationRunStatus $status): bool => $status->isTerminal()),
        ));
        $rows = $this->connection->fetchAllAssociative(
            'SELECT i.id, i.run_id, i.trigger_type, i.actor_type, i.actor_user_id, i.dirty'
            . ' FROM ' . Installer::TABLE_AUTOMATIC_ORGANIZE_INTENT . ' i'
            . ' LEFT JOIN ' . Installer::TABLE_OPERATION_RUN . ' r ON r.id = i.run_id'
            . ' WHERE r.id IS NULL OR r.status IN (:terminal)'
            . ' ORDER BY i.updated_at ASC, i.id ASC'
            . ' LIMIT ' . max(1, $limit),
            ['terminal' => $terminal],
            ['terminal' => ArrayParameterType::STRING],
        );

        return array_map(static fn (array $row): AutomaticOrganizeIntent => new AutomaticOrganizeIntent(
            (int) $row['id'],
            (string) $row['run_id'],
            TriggerType::from((string) $row['trigger_type']),
            OperationRunActor::fromRun($row),
            (bool) $row['dirty'],
        ), $rows);
    }

    protected function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
