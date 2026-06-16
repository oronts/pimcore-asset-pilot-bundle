<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Query;

use Oronts\AssetPilotBundle\Enum\ConfidenceLevel;

final class ConfidenceFilter
{
    /**
     * Builds the WHERE conditions for a confidence bucket, mirroring ConfidenceScorer::classify so
     * the buckets are mutually exclusive: a locked asset is only "protected", an audit-logged asset
     * is only "historically_used", and the three age buckets exclude both. Without this the buckets
     * overlapped and "protected" matched nothing.
     *
     * @return array{conditions: string[], params: array<string, int|string>}
     */
    public static function build(
        ConfidenceLevel $level,
        string $lockProperty,
        string $auditTable,
        int $now,
        int $recentlyUploadedDays,
        int $probablyUnusedDays,
    ): array {
        $locked = 'EXISTS (SELECT 1 FROM properties cfp WHERE cfp.cid = a.id AND cfp.ctype = :cf_ctype AND cfp.name = :cf_lock_prop AND cfp.data = \'1\')';
        $inHistory = 'a.id IN (SELECT DISTINCT pal.asset_id FROM ' . $auditTable . ' pal)';
        $params = ['cf_ctype' => 'asset', 'cf_lock_prop' => $lockProperty];

        if ($level === ConfidenceLevel::Protected) {
            return ['conditions' => [$locked], 'params' => $params];
        }

        $conditions = ['NOT ' . $locked];

        if ($level === ConfidenceLevel::HistoricallyUsed) {
            $conditions[] = $inHistory;

            return ['conditions' => $conditions, 'params' => $params];
        }

        $conditions[] = 'NOT ' . $inHistory;
        $recent = $now - ($recentlyUploadedDays * 86400);
        $old = $now - ($probablyUnusedDays * 86400);

        // Boundaries mirror ConfidenceScorer's strict day cutoffs (< 30, < 90): an asset modified
        // exactly $recentDays ago must score the same here as in the scorer, not flip buckets.
        if ($level === ConfidenceLevel::RecentlyUploaded) {
            $conditions[] = 'a.modificationDate > :conf_recent';
            $params['conf_recent'] = $recent;
        } elseif ($level === ConfidenceLevel::ProbablyUnused) {
            $conditions[] = 'a.modificationDate > :conf_old AND a.modificationDate <= :conf_recent';
            $params['conf_old'] = $old;
            $params['conf_recent'] = $recent;
        } elseif ($level === ConfidenceLevel::DefinitelyUnused) {
            $conditions[] = 'a.modificationDate <= :conf_old';
            $params['conf_old'] = $old;
        }

        return ['conditions' => $conditions, 'params' => $params];
    }
}
