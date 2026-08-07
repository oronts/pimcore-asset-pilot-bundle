<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\SingleRunOutcomeKind;

final readonly class SingleRunOutcome
{
    /** @param list<OperationResult> $results */
    private function __construct(
        public SingleRunOutcomeKind $kind,
        public array $results,
        public ?\Throwable $cause,
    ) {}

    /** @param list<OperationResult> $results */
    public static function completed(array $results): self
    {
        return new self(SingleRunOutcomeKind::Completed, $results, null);
    }

    public static function claimConflict(): self
    {
        return new self(SingleRunOutcomeKind::ClaimConflict, [], null);
    }

    public static function leaseLost(): self
    {
        return new self(SingleRunOutcomeKind::LeaseLost, [], null);
    }

    public static function stale(): self
    {
        return new self(SingleRunOutcomeKind::Stale, [], null);
    }

    public static function failed(\Throwable $cause): self
    {
        return new self(SingleRunOutcomeKind::Failed, [], $cause);
    }
}
