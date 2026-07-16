<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Health\Check;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryStatus;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Health\HealthCheckInterface;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Model\HealthCheckResult;
use Psr\Log\LoggerInterface;

class OperationJournalHealthCheck implements HealthCheckInterface
{
    private const int SAMPLE_LIMIT = 20;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly int $recoveryAfterSeconds,
    ) {
        if ($recoveryAfterSeconds <= 0) {
            throw new \InvalidArgumentException('The operation journal recovery interval must be positive.');
        }
    }

    public function name(): string
    {
        return 'operation_journal';
    }

    public function run(): HealthCheckResult
    {
        try {
            $details = $this->snapshot();
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: could not inspect operation journal health: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return new HealthCheckResult(
                $this->name(),
                HealthStatus::Warning,
                'Could not inspect operation recovery state. Verify the audit and durable-delivery tables and database connectivity.',
                ['recovery_after_seconds' => $this->recoveryAfterSeconds],
            );
        }

        if ($details['journal']['recovery_required'] > 0 || $details['deliveries']['dead'] > 0) {
            return new HealthCheckResult(
                $this->name(),
                HealthStatus::Critical,
                'Operation recovery needs intervention. Inspect the listed recovery-required operations and dead observer deliveries, then run operation recovery and durable-delivery maintenance.',
                $details,
            );
        }

        if ($details['journal']['in_progress'] > 0 || $details['deliveries']['overdue'] > 0) {
            return new HealthCheckResult(
                $this->name(),
                HealthStatus::Warning,
                'Operation recovery is behind. Run operation recovery and durable-delivery maintenance, then verify the listed journal and delivery IDs.',
                $details,
            );
        }

        return new HealthCheckResult(
            $this->name(),
            HealthStatus::Ok,
            'Operation journal recovery and durable observer delivery are healthy.',
            $details,
        );
    }

    /**
     * @return array{
     *     recovery_after_seconds: int,
     *     cutoff: string,
     *     journal: array{in_progress: int, recovery_required: int, oldest_updated_at: ?string, samples: list<array{id: int, asset_id: int, status: string, updated_at: string}>},
     *     deliveries: array{dead: int, overdue: int, oldest_overdue_at: ?string, samples: list<array{id: string, operation_id: int, observer_id: string, status: string, available_at: string, locked_until: ?string, last_error: ?string}>}
     * }
     */
    protected function snapshot(): array
    {
        $now = $this->now();
        $cutoff = $this->format($now->modify(sprintf('-%d seconds', $this->recoveryAfterSeconds)));

        return [
            'recovery_after_seconds' => $this->recoveryAfterSeconds,
            'cutoff' => $cutoff,
            'journal' => $this->journalDetails($cutoff),
            'deliveries' => $this->deliveryDetails($cutoff),
        ];
    }

    /** @return array{in_progress: int, recovery_required: int, oldest_updated_at: ?string, samples: list<array{id: int, asset_id: int, status: string, updated_at: string}>} */
    private function journalDetails(string $cutoff): array
    {
        $journalRows = $this->connection->createQueryBuilder()
            ->select('status', 'COUNT(*) AS row_count', 'MIN(updated_at) AS oldest_updated_at')
            ->from(Installer::TABLE_AUDIT_LOG)
            ->andWhere($this->journalCondition())
            ->setParameters($this->journalParameters($cutoff))
            ->groupBy('status')
            ->executeQuery()
            ->fetchAllAssociative();
        $counts = [OperationStatus::InProgress->value => 0, OperationStatus::RecoveryRequired->value => 0];
        $oldestJournal = null;
        foreach ($journalRows as $row) {
            $status = (string) $row['status'];
            $counts[$status] = (int) $row['row_count'];
            $oldestJournal = $this->oldest($oldestJournal, $row['oldest_updated_at']);
        }

        $samples = $this->connection->createQueryBuilder()
            ->select('id', 'asset_id', 'status', 'updated_at')
            ->from(Installer::TABLE_AUDIT_LOG)
            ->andWhere($this->journalCondition())
            ->setParameters($this->journalParameters($cutoff))
            ->orderBy('updated_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults(self::SAMPLE_LIMIT)
            ->executeQuery()
            ->fetchAllAssociative();

        return [
            'in_progress' => $counts[OperationStatus::InProgress->value],
            'recovery_required' => $counts[OperationStatus::RecoveryRequired->value],
            'oldest_updated_at' => $oldestJournal,
            'samples' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'asset_id' => (int) $row['asset_id'],
                'status' => (string) $row['status'],
                'updated_at' => (string) $row['updated_at'],
            ], $samples),
        ];
    }

    /** @return array{dead: int, overdue: int, oldest_overdue_at: ?string, samples: list<array{id: string, operation_id: int, observer_id: string, status: string, available_at: string, locked_until: ?string, last_error: ?string}>} */
    private function deliveryDetails(string $cutoff): array
    {
        $dead = (int) $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Installer::TABLE_OPERATION_DELIVERY)
            ->where('status = :dead')
            ->setParameter('dead', OperationDeliveryStatus::Dead->value)
            ->executeQuery()
            ->fetchOne();
        $overdueQuery = $this->overdueDeliveryQuery($cutoff);
        $overdue = (int) (clone $overdueQuery)
            ->select('COUNT(*)')
            ->executeQuery()
            ->fetchOne();
        $oldestOverdue = (clone $overdueQuery)
            ->select('MIN(CASE WHEN status = :processing THEN locked_until ELSE available_at END)')
            ->executeQuery()
            ->fetchOne();
        $samples = (clone $overdueQuery)
            ->select('id', 'operation_id', 'observer_id', 'status', 'available_at', 'locked_until', 'last_error')
            ->orWhere('status = :dead')
            ->setParameter('dead', OperationDeliveryStatus::Dead->value)
            ->orderBy('updated_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults(self::SAMPLE_LIMIT)
            ->executeQuery()
            ->fetchAllAssociative();

        return [
            'dead' => $dead,
            'overdue' => $overdue,
            'oldest_overdue_at' => $oldestOverdue === false || $oldestOverdue === null ? null : (string) $oldestOverdue,
            'samples' => array_map(static fn (array $row): array => [
                'id' => (string) $row['id'],
                'operation_id' => (int) $row['operation_id'],
                'observer_id' => (string) $row['observer_id'],
                'status' => (string) $row['status'],
                'available_at' => (string) $row['available_at'],
                'locked_until' => $row['locked_until'] === null ? null : (string) $row['locked_until'],
                'last_error' => $row['last_error'] === null ? null : (string) $row['last_error'],
            ], $samples),
        ];
    }

    private function overdueDeliveryQuery(string $cutoff): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->from(Installer::TABLE_OPERATION_DELIVERY)
            ->where('((status IN (:pending, :retry) AND available_at <= :cutoff) OR (status = :processing AND locked_until IS NOT NULL AND locked_until <= :cutoff))')
            ->setParameter('pending', OperationDeliveryStatus::Pending->value)
            ->setParameter('retry', OperationDeliveryStatus::Retry->value)
            ->setParameter('processing', OperationDeliveryStatus::Processing->value)
            ->setParameter('cutoff', $cutoff);
    }

    private function journalCondition(): string
    {
        return 'status IN (:inProgress, :recoveryRequired) AND updated_at IS NOT NULL AND updated_at <= :cutoff';
    }

    /** @return array<string, string> */
    private function journalParameters(string $cutoff): array
    {
        return [
            'inProgress' => OperationStatus::InProgress->value,
            'recoveryRequired' => OperationStatus::RecoveryRequired->value,
            'cutoff' => $cutoff,
        ];
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function oldest(?string $current, mixed $candidate): ?string
    {
        if ($candidate === null || $candidate === false) {
            return $current;
        }
        $candidate = (string) $candidate;

        return $current === null || $candidate < $current ? $candidate : $current;
    }

    private function format(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
