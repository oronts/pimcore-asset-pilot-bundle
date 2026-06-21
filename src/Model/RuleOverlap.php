<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

/**
 * Two enabled rules that can match the same (class, field) and therefore compete for the same
 * assets. The higher-priority rule wins per asset (AssetOrganizer keeps the highest match), so the
 * lower one may be shadowed. Equal priority is ambiguous and worth a warning beyond the existing
 * duplicate-priority check.
 */
readonly class RuleOverlap
{
    /**
     * @param list<string> $sharedFields ['*'] when both target all fields, otherwise the overlapping field names
     */
    public function __construct(
        public string $ruleA,
        public string $ruleB,
        public string $class,
        public array $sharedFields,
        public ?string $higherPriority,
        public bool $samePriority,
    ) {}
}
