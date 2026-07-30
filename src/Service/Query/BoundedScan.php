<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Query;

/**
 * Bounds an authorized scan-and-fill so a workspace-restricted user cannot force an O(entire class/folder)
 * walk. It walks raw id windows and hands each id to $collect, which loads/authorizes and accumulates it
 * and returns true once the caller's page is full. The scan stops at $maxCandidates raw ids and reports
 * whether it was truncated: a further id genuinely exists past the budget (probed once, O(1)). It never
 * reports truncated for a filled page or a source that was exhausted within the budget.
 */
class BoundedScan
{
    /**
     * @param callable(int $offset, int $limit): list<int> $window  fetch raw ids for (offset, limit)
     * @param callable(int $id): bool                       $collect returns true when the page is full
     */
    public static function run(callable $window, callable $collect, int $maxCandidates, int $batchSize): bool
    {
        $batch = max(1, $batchSize);
        $rawOffset = 0;
        $scanned = 0;

        while ($scanned < $maxCandidates) {
            $ids = array_values($window($rawOffset, $batch));
            if ($ids === []) {
                return false;
            }
            foreach ($ids as $i => $id) {
                ++$scanned;
                if ($collect($id)) {
                    return false;
                }
                if ($scanned >= $maxCandidates) {
                    return $window($rawOffset + $i + 1, 1) !== [];
                }
            }
            $rawOffset += count($ids);
            if (count($ids) < $batch) {
                return false;
            }
        }

        return false;
    }
}
