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

readonly class RulePreviewPlanService implements RulePreviewPlanServiceInterface
{
    public function __construct(
        private ApplyPlanServiceInterface $plans,
        private OrganizePlanFingerprint $fingerprints,
    ) {}

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
            'operations' => MoveOperationSnapshot::list($operations),
            'rule' => $rule->name,
        ];

        return new ApplyPlan(
            'rule-apply',
            $actor,
            $request,
            ['rule' => $rule->toConfigArray()],
            [new ApplyPlanTarget('object:' . $objectId, $this->fingerprints->forOperations($object, $operations))],
        );
    }

    private function status(ApplyPlanStatus $status): RulePreviewPlanStatus
    {
        return match ($status) {
            ApplyPlanStatus::Malformed => RulePreviewPlanStatus::Malformed,
            ApplyPlanStatus::Valid => RulePreviewPlanStatus::Valid,
            default => RulePreviewPlanStatus::Stale,
        };
    }

}
