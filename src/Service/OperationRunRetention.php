<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Installer;

class OperationRunRetention implements OperationRunRetentionInterface
{
    private readonly ?\Closure $clock;

    public function __construct(
        private readonly Connection $connection,
        private readonly int $retentionDays,
        private readonly int $batchSize,
        ?\Closure $clock = null,
    ) {
        if ($retentionDays < 1) {
            throw new \InvalidArgumentException('Operation run retention must be at least one day.');
        }
        if ($batchSize < 1 || $batchSize > 1_000) {
            throw new \InvalidArgumentException('Operation run retention batch size must be between 1 and 1000.');
        }

        $this->clock = $clock;
    }

    public function prune(): int
    {
        $cutoff = $this->now()->modify(sprintf('-%d days', $this->retentionDays))->format('Y-m-d H:i:s');
        $statuses = array_map(
            static fn (OperationRunStatus $status): string => $status->value,
            array_filter(OperationRunStatus::cases(), static fn (OperationRunStatus $status): bool => $status->isTerminal()),
        );
        $candidates = $this->connection->createQueryBuilder()
            ->select('run.id')
            ->from(Installer::TABLE_OPERATION_RUN, 'run')
            ->where('run.status IN (:statuses)')
            ->andWhere('run.updated_at < :cutoff')
            ->andWhere('NOT EXISTS (SELECT 1 FROM ' . Installer::TABLE_OPERATION_RUN . ' child WHERE child.retry_of = run.id)')
            ->setParameter('statuses', $statuses, ArrayParameterType::STRING)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('run.updated_at', 'ASC')
            ->addOrderBy('run.id', 'ASC')
            ->setMaxResults($this->batchSize)
            ->executeQuery()
            ->fetchFirstColumn();

        $deleted = 0;
        foreach ($candidates as $runId) {
            $deleted += $this->pruneRun((string) $runId, $statuses, $cutoff);
        }

        return $deleted;
    }

    /** @param list<string> $terminalStatuses */
    private function pruneRun(string $runId, array $terminalStatuses, string $cutoff): int
    {
        return $this->connection->transactional(function () use ($runId, $terminalStatuses, $cutoff): int {
            $this->lockRun($runId);
            $eligible = (int) $this->connection->executeQuery(
                'SELECT COUNT(*) FROM ' . Installer::TABLE_OPERATION_RUN . ' run WHERE run.id = ? AND run.status IN (?) AND run.updated_at < ? AND NOT EXISTS (SELECT 1 FROM ' . Installer::TABLE_OPERATION_RUN . ' child WHERE child.retry_of = run.id)',
                [$runId, $terminalStatuses, $cutoff],
                [\Doctrine\DBAL\ParameterType::STRING, ArrayParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING],
            )->fetchOne();
            if ($eligible !== 1) {
                return 0;
            }

            $this->connection->delete(Installer::TABLE_OPERATION_RUN_ITEM, ['run_id' => $runId]);

            return $this->connection->delete(Installer::TABLE_OPERATION_RUN, ['id' => $runId]);
        });
    }

    private function lockRun(string $runId): void
    {
        $query = $this->connection->createQueryBuilder()
            ->select('id')
            ->from(Installer::TABLE_OPERATION_RUN)
            ->where('id = :id')
            ->setParameter('id', $runId);
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $query->forUpdate();
        }
        $query->executeQuery()->fetchOne();
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock === null ? new \DateTimeImmutable('now', new \DateTimeZone('UTC')) : ($this->clock)();
    }
}
