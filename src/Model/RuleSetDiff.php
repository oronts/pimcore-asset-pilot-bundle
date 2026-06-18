<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

/**
 * The difference between an imported rule-set artifact and the rules currently configured.
 * Rules are config-only (Decision #11), so this never persists: it reports what a target
 * environment would gain/lose/change if the imported set were committed.
 */
readonly class RuleSetDiff
{
    /**
     * @param array<string, array<string, mixed>>                                $added     name => imported config
     * @param array<string, array<string, mixed>>                                $removed   name => current config
     * @param array<string, array{current: array<string, mixed>, imported: array<string, mixed>}> $changed
     * @param list<string>                                                       $unchanged rule names
     */
    public function __construct(
        public array $added,
        public array $removed,
        public array $changed,
        public array $unchanged,
    ) {}

    public function hasChanges(): bool
    {
        return $this->added !== [] || $this->removed !== [] || $this->changed !== [];
    }
}
