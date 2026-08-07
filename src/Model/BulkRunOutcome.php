<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\BulkRunOutcomeKind;

final readonly class BulkRunOutcome
{
    private function __construct(
        public BulkRunOutcomeKind $kind,
        public ?BulkOrganizeReport $report,
        public ?\Throwable $cause,
    ) {}

    public static function completed(BulkOrganizeReport $report): self
    {
        return new self(BulkRunOutcomeKind::Completed, $report, null);
    }

    public static function ownershipLost(\Throwable $cause): self
    {
        return new self(BulkRunOutcomeKind::OwnershipLost, null, $cause);
    }

    public static function failed(\Throwable $cause): self
    {
        return new self(BulkRunOutcomeKind::Failed, null, $cause);
    }
}
