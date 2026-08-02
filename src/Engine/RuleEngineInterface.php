<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Engine;

use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Model\RuleMatch;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

interface RuleEngineInterface
{
    /**
     * Supported convenience API: evaluate the object's rules against the asset with no field or locale scoping.
     * A rule's field constraint is not applied and locale-scoped rules are excluded, so this is a coarse check.
     * Use matchField() for field- and locale-scoped matching, or explain() for the matches plus the evaluation trace.
     *
     * @return RuleMatch[]
     */
    public function match(AbstractObject $object, Asset $asset): array;

    /** @return RuleMatch[] */
    public function matchField(AbstractObject $object, Asset $asset, string $fieldName, ?string $locale = null): array;

    /** @return array{matches: RuleMatch[], evaluations: \Oronts\AssetPilotBundle\Model\RuleEvaluation[]} */
    public function explain(AbstractObject $object, Asset $asset, ?string $fieldName = null, ?string $locale = null): array;

    /** @return Rule[] */
    public function getRules(): array;
}
