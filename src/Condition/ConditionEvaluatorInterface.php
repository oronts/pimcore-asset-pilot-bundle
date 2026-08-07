<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Condition;

use Oronts\AssetPilotBundle\Model\Rule;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

interface ConditionEvaluatorInterface
{
    /** Evaluate the rule condition, returning false (not throwing) on any error. */
    public function evaluate(AbstractObject $object, Asset $asset, Rule $rule, ?string $locale = null): bool;

    /** Like evaluate() but lets evaluation errors propagate, so callers (explain) can report them. */
    public function evaluateStrict(AbstractObject $object, Asset $asset, Rule $rule, ?string $locale = null): bool;

    public function validateSyntax(string $expression): void;
}
