<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\OperationRunKind;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\OperationRunExecution;
use Oronts\AssetPilotBundle\Security\ActorContextStore;

class OperationRunExecutor implements OperationRunExecutorInterface
{
    public function __construct(
        private readonly OrganizeDispatcherInterface $dispatcher,
        private readonly DuplicateMergeServiceInterface $duplicateMerges,
        private readonly ActorContextStore $actors,
    ) {}

    public function supports(OperationRunKind $kind): bool
    {
        return in_array($kind, [
            OperationRunKind::Organize,
            OperationRunKind::Reorganize,
            OperationRunKind::Replay,
            OperationRunKind::DuplicateMerge,
        ], true);
    }

    public function execute(OperationRunKind $kind, string $runId, array $run, ActorContext $actor): OperationRunExecution
    {
        return match ($kind) {
            OperationRunKind::Organize,
            OperationRunKind::Reorganize,
            OperationRunKind::Replay => $this->dispatchOrganization($runId, $run, $actor),
            OperationRunKind::DuplicateMerge => $this->resumeDuplicateMerge($runId, $actor),
        };
    }

    /** @param array<string, mixed> $run */
    private function dispatchOrganization(string $runId, array $run, ActorContext $actor): OperationRunExecution
    {
        $objectIds = [];
        $fingerprints = [];
        foreach ($run['items'] ?? [] as $item) {
            if (!is_array($item) || ($item['target_type'] ?? null) !== 'data_object' || (int) ($item['target_id'] ?? 0) <= 0) {
                throw new \InvalidArgumentException('The retry run contains an unsupported target.');
            }

            $objectId = (int) $item['target_id'];
            $objectIds[] = $objectId;
            if (is_string($item['fingerprint'] ?? null) && $item['fingerprint'] !== '') {
                $fingerprints[$objectId] = $item['fingerprint'];
            }
        }
        $objectIds = array_values(array_unique($objectIds));
        if ($objectIds === []) {
            throw new \InvalidArgumentException('The retry run contains no supported object targets.');
        }

        $request = is_array($run['request_payload'] ?? null) ? $run['request_payload'] : [];
        $trigger = TriggerType::tryFrom((string) ($request['trigger'] ?? '')) ?? TriggerType::Api;
        if (count($objectIds) === 1) {
            $this->dispatcher->dispatchObject($objectIds[0], $trigger, $actor, $runId, $fingerprints[$objectIds[0]] ?? null);
        } else {
            $this->dispatcher->dispatchBulk($objectIds, $trigger, $actor, $runId, $fingerprints);
        }

        return new OperationRunExecution(OperationRunStatus::Queued, true);
    }

    private function resumeDuplicateMerge(string $runId, ActorContext $actor): OperationRunExecution
    {
        $outcome = $this->actors->runAs($actor, fn () => $this->duplicateMerges->resume($runId));

        return new OperationRunExecution(
            $outcome->status ?? OperationRunStatus::Running,
            false,
            $outcome->dispositions,
        );
    }
}
