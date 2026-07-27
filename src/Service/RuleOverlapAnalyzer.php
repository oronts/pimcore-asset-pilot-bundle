<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Model\RuleOverlap;

/**
 * Static analysis of the rule set: finds pairs of enabled rules that may match the same
 * (class, field) and so compete for the same assets. Pure, catalog-free — it inspects the rule
 * definitions only, never scans objects, so it is safe to run synchronously.
 */
class RuleOverlapAnalyzer implements RuleOverlapAnalyzerInterface
{
    public function __construct(
        protected readonly RuleEngineInterface $ruleEngine,
    ) {}

    /**
     * @return list<RuleOverlap>
     */
    public function analyze(): array
    {
        $rules = array_values(array_filter(
            $this->ruleEngine->getRules(),
            static fn (Rule $rule): bool => $rule->enabled,
        ));

        $overlaps = [];
        $count = count($rules);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                $a = $rules[$i];
                $b = $rules[$j];

                if (!$this->classesOverlap($a, $b)) {
                    continue;
                }
                if (!$this->valuesOverlap($a->locales, $b->locales)
                    || !$this->valuesOverlap($a->filters['types'] ?? [], $b->filters['types'] ?? [])
                    || !$this->valuesOverlap($a->filters['extensions'] ?? [], $b->filters['extensions'] ?? [])
                    || !$this->sizeRangesOverlap($a, $b)) {
                    continue;
                }

                $shared = $this->sharedFields($a, $b);
                if ($shared === null) {
                    continue;
                }

                $samePriority = $a->priority === $b->priority;
                $overlaps[] = new RuleOverlap(
                    ruleA: $a->name,
                    ruleB: $b->name,
                    class: $this->overlapClass($a, $b),
                    sharedFields: $shared,
                    higherPriority: $samePriority ? null : ($a->priority > $b->priority ? $a->name : $b->name),
                    samePriority: $samePriority,
                );
            }
        }

        return $overlaps;
    }

    protected function classesOverlap(Rule $a, Rule $b): bool
    {
        return $a->class === '*' || $b->class === '*' || $a->class === $b->class;
    }

    protected function overlapClass(Rule $a, Rule $b): string
    {
        if ($a->class === $b->class) {
            return $a->class;
        }

        return $a->class === '*' ? $b->class : $a->class;
    }

    /**
     * @return list<string>|null ['*'] when both target all fields, the field intersection otherwise,
     *                           null when their field sets are disjoint (no overlap)
     */
    protected function sharedFields(Rule $a, Rule $b): ?array
    {
        $aAll = $a->fields === [];
        $bAll = $b->fields === [];

        if ($aAll && $bAll) {
            return ['*'];
        }
        if ($aAll) {
            return array_values($b->fields);
        }
        if ($bAll) {
            return array_values($a->fields);
        }

        $intersection = array_values(array_intersect($a->fields, $b->fields));

        return $intersection === [] ? null : $intersection;
    }

    /** @param list<string> $a @param list<string> $b */
    private function valuesOverlap(array $a, array $b): bool
    {
        return $a === [] || $b === [] || array_intersect($a, $b) !== [];
    }

    private function sizeRangesOverlap(Rule $a, Rule $b): bool
    {
        $aMin = (int) ($a->filters['min_size'] ?? 0);
        $bMin = (int) ($b->filters['min_size'] ?? 0);
        $aMax = isset($a->filters['max_size']) ? (int) $a->filters['max_size'] : PHP_INT_MAX;
        $bMax = isset($b->filters['max_size']) ? (int) $b->filters['max_size'] : PHP_INT_MAX;

        return $aMin <= $bMax && $bMin <= $aMax;
    }
}
