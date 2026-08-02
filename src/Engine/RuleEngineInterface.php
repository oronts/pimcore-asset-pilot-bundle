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
     * Supported convenience API: every RuleMatch for the asset across all of the object's fields in one call.
     * Use matchField() to scope to a single field/locale, or explain() for the matches plus the evaluation trace.
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
