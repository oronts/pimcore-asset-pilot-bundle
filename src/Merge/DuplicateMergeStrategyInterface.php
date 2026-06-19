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
     * Dispose of one duplicate copy after the repointer has run for it. A strategy MUST NOT destroy a
     * copy whose references were not fully repointed ({@see RepointReport::$fullyRepointed} is false).
     */
    public function disposeCopy(int $copyId, RepointReport $report): CopyDisposition;
}
