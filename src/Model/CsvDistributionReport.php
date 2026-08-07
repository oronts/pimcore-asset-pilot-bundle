<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\CsvDistributionOutcome;

/** The immutable summary of a CSV distribution run (preview or apply). */
readonly class CsvDistributionReport
{
    /** @param list<CsvDistributionResult> $results */
    public function __construct(
        public array $results,
        public bool $dryRun,
    ) {}

    public function total(): int
    {
        return count($this->results);
    }

    public function countOf(CsvDistributionOutcome $outcome): int
    {
        return count(array_filter($this->results, static fn (CsvDistributionResult $result): bool => $result->outcome === $outcome));
    }

    /** Rows the operator must fix (unknown asset, missing target, or malformed row). */
    public function problemCount(): int
    {
        return count(array_filter($this->results, static fn (CsvDistributionResult $result): bool => $result->outcome->isProblem()));
    }
}
