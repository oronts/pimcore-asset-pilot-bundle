<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Enum\RulePreviewPlanStatus;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Model\Rule;
use Pimcore\Model\DataObject\AbstractObject;

final readonly class RulePreviewPlanService
{
    public function __construct(private ApplyPlanServiceInterface $plans) {}

    /** @param list<MoveOperation> $operations */
    public function issue(Rule $rule, AbstractObject $object, ActorContext $actor, array $operations): string
    {
        return $this->plans->issue($this->plan($rule, $object, $actor, $operations));
    }

    /** @param list<MoveOperation> $operations */
    public function verify(
        string $token,
        Rule $rule,
        AbstractObject $object,
        ActorContext $actor,
        array $operations,
    ): RulePreviewPlanStatus {
        return $this->status($this->plans->verify($token, $this->plan($rule, $object, $actor, $operations)));
    }

    /** @param list<MoveOperation> $operations */
    public function claim(
        string $token,
        Rule $rule,
        AbstractObject $object,
        ActorContext $actor,
        array $operations,
    ): RulePreviewPlanStatus {
        $status = $this->plans->claim($token, $this->plan($rule, $object, $actor, $operations));

        return $status === ApplyPlanStatus::Claimed
            ? RulePreviewPlanStatus::Valid
            : $this->status($status);
    }

    /** @param list<MoveOperation> $operations */
    private function plan(
        Rule $rule,
        AbstractObject $object,
        ActorContext $actor,
        array $operations,
    ): ApplyPlan {
        $objectId = (int) $object->getId();
        $request = [
            'objectId' => $objectId,
            'objectModifiedAt' => $object->getModificationDate(),
            'operations' => $this->operations($operations),
            'rule' => $rule->name,
        ];

        return new ApplyPlan(
            'rule-apply',
            $actor,
            $request,
            ['rule' => $rule->toConfigArray()],
            [new ApplyPlanTarget('object:' . $objectId, hash('sha256', $this->encode($request)))],
        );
    }

    /** @param list<MoveOperation> $operations @return list<array<string, int|string|null>> */
    private function operations(array $operations): array
    {
        $snapshot = array_map(static fn (MoveOperation $operation): array => [
            'assetId' => $operation->assetId,
            'error' => $operation->errorMessage,
            'objectClass' => $operation->objectClass,
            'objectId' => $operation->objectId,
            'rule' => $operation->ruleName,
            'source' => $operation->sourcePath,
            'status' => $operation->status->value,
            'target' => $operation->targetPath,
        ], $operations);
        usort($snapshot, fn (array $left, array $right): int => $this->encode($left) <=> $this->encode($right));

        return $snapshot;
    }

    private function status(ApplyPlanStatus $status): RulePreviewPlanStatus
    {
        return match ($status) {
            ApplyPlanStatus::Malformed => RulePreviewPlanStatus::Malformed,
            ApplyPlanStatus::Valid => RulePreviewPlanStatus::Valid,
            default => RulePreviewPlanStatus::Stale,
        };
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
