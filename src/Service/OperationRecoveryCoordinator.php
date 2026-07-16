<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Exception\OperationRecoveryPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Model\OperationRecoveryResult;
use Oronts\AssetPilotBundle\Model\ReviewedOperationRecovery;
use Oronts\AssetPilotBundle\Support\BulkIds;

final readonly class OperationRecoveryCoordinator implements OperationRecoveryCoordinatorInterface
{
    /** @param array<string, mixed> $planConfiguration */
    public function __construct(
        private OperationRecoveryService $recovery,
        private ApplyPlanServiceInterface $plans,
        private array $planConfiguration,
    ) {}

    public function preview(int $limit, ActorContext $actor): ReviewedOperationRecovery
    {
        $limit = $this->limit($limit);
        $results = $this->recovery->preview($limit);

        return new ReviewedOperationRecovery(
            $results,
            $results === [] ? null : $this->plans->issue($this->plan($limit, $actor, $results)),
            false,
        );
    }

    public function apply(int $limit, ActorContext $actor, string $planToken): ReviewedOperationRecovery
    {
        $limit = $this->limit($limit);
        $preview = $this->recovery->preview($limit);
        if ($preview === []) {
            throw new OperationRecoveryPlanException(ApplyPlanStatus::Stale);
        }

        $claim = $this->plans->claim($planToken, $this->plan($limit, $actor, $preview));
        if ($claim !== ApplyPlanStatus::Claimed) {
            throw new OperationRecoveryPlanException($claim);
        }

        $operationIds = array_map(
            static fn (OperationRecoveryResult $result): int => $result->operationId,
            $preview,
        );
        $results = $this->recovery->recover($limit, $operationIds);
        $recoveredIds = array_map(
            static fn (OperationRecoveryResult $result): int => $result->operationId,
            $results,
        );
        sort($operationIds, SORT_NUMERIC);
        sort($recoveredIds, SORT_NUMERIC);
        if ($operationIds !== $recoveredIds) {
            throw new OperationRecoveryPlanException(ApplyPlanStatus::Stale);
        }

        return new ReviewedOperationRecovery($results, null, true);
    }

    /** @param list<OperationRecoveryResult> $results */
    private function plan(int $limit, ActorContext $actor, array $results): ApplyPlan
    {
        return new ApplyPlan(
            'operation-recovery',
            $actor,
            ['limit' => $limit],
            $this->planConfiguration,
            array_map(
                static fn (OperationRecoveryResult $result): ApplyPlanTarget => new ApplyPlanTarget(
                    (string) $result->operationId,
                    $result->fingerprint,
                ),
                $results,
            ),
        );
    }

    private function limit(int $limit): int
    {
        if ($limit < 1 || $limit > BulkIds::MAX) {
            throw new \InvalidArgumentException(sprintf('Operation recovery limit must be between 1 and %d.', BulkIds::MAX));
        }

        return $limit;
    }
}
