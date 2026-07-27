<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\RulePreviewPlanStatus;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Model\Rule;
use Pimcore\Model\DataObject\AbstractObject;

interface RulePreviewPlanServiceInterface
{
    /** @param list<MoveOperation> $operations */
    public function issue(Rule $rule, AbstractObject $object, ActorContext $actor, array $operations): string;

    /** @param list<MoveOperation> $operations */
    public function verify(string $token, Rule $rule, AbstractObject $object, ActorContext $actor, array $operations): RulePreviewPlanStatus;

    /** @param list<MoveOperation> $operations */
    public function claim(string $token, Rule $rule, AbstractObject $object, ActorContext $actor, array $operations): RulePreviewPlanStatus;
}
