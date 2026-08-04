<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

/** The outcome of a single CSV distribution row. */
enum CsvDistributionOutcome: string
{
    /** Dry-run: the asset would move to the target folder. */
    case Planned = 'planned';
    /** Apply: the asset was moved to the target folder. */
    case Moved = 'moved';
    /** The asset is already directly under the target folder; nothing to do. */
    case Skipped = 'skipped';
    /** No asset matched the row's reference (id or path). */
    case AssetNotFound = 'asset_not_found';
    /** The target folder does not exist (this tool never creates folders implicitly). */
    case TargetNotFound = 'target_not_found';
    /** The row was malformed (missing a required column value) or the move failed. */
    case Invalid = 'invalid';

    /** Whether this outcome represents an unresolved input the operator should fix. */
    public function isProblem(): bool
    {
        return match ($this) {
            self::AssetNotFound, self::TargetNotFound, self::Invalid => true,
            self::Planned, self::Moved, self::Skipped => false,
        };
    }
}
