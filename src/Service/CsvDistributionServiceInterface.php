<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\CsvDistributionReport;

/**
 * Distributes existing assets into target folders from a CSV mapping (F23), for one-off imports and
 * migrations that place assets by an external plan rather than by rules. Alias this interface to replace
 * the default resolution/move behavior.
 */
interface CsvDistributionServiceInterface
{
    /**
     * Resolve every row of $csvPath (its $assetColumn to an asset, its $targetColumn to a folder) and,
     * unless $dryRun, move each asset directly under its target folder. Every row yields one result;
     * unresolved rows are reported, not fatal. Throws only when the file or its header is unusable.
     */
    public function distribute(string $csvPath, string $assetColumn, string $targetColumn, bool $dryRun): CsvDistributionReport;
}
