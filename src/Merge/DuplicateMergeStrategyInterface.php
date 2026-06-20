<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Merge;

/**
 * The pluggable disposition policy of a duplicate merge: once the canonical asset is chosen and a
 * copy's references have been repointed onto it, the strategy decides what becomes of that copy
 * (quarantine, delete, leave). Implementations are tagged `oronts_asset_pilot.duplicate_merge_strategy`
 * and selected by {@see self::name()} from config or the API, so each project can pick a built-in
 * policy or ship its own.
 */
interface DuplicateMergeStrategyInterface
{
    /** Stable key used to select this strategy from config / the API (e.g. 'quarantine'). */
    public function name(): string;

    /**
     * Whether the merge service should repoint this copy's references onto the canonical before
     * {@see self::disposeCopy()}. Repointing strategies (delete, quarantine) return true; a cautious
     * "isolate" policy returns false so genuinely-referenced copies are left untouched rather than
     * silently rewritten.
     */
    public function repointsReferences(): bool;

    /**
     * Dispose of one duplicate copy. For a repointing strategy the repointer has already run (and a
     * strategy MUST NOT destroy a copy whose references were not fully repointed, i.e.
     * {@see RepointReport::$fullyRepointed} is false); for a non-repointing strategy the report is
     * neutral and the copy may still be referenced.
     */
    public function disposeCopy(int $copyId, RepointReport $report): CopyDisposition;
}
