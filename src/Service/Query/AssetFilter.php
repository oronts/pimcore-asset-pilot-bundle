<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Query;

/**
 * Builds the shared `assets`-table WHERE clause for the folder/type/extension filter vocabulary used
 * by the integrity, duplicate, and normalize scans, so the (LIKE-escaped, bound) filter logic lives
 * in one tested place. Returns [conditionSql, params]; the condition is '' when no filter applies.
 */
final class AssetFilter
{
    /**
     * @param array{type?: string, folder?: string, extension?: string} $filters
     * @return array{0: string, 1: list<string>}
     */
    public static function condition(array $filters, bool $excludeFolders = false): array
    {
        $conditions = [];
        $params = [];

        if ($excludeFolders) {
            $conditions[] = "type != 'folder'";
        }
        if (!empty($filters['folder'])) {
            $conditions[] = 'path LIKE ?';
            $params[] = Like::escape(rtrim((string) $filters['folder'], '/') . '/') . '%';
        }
        if (!empty($filters['type'])) {
            $conditions[] = 'type = ?';
            $params[] = (string) $filters['type'];
        }
        if (!empty($filters['extension'])) {
            $conditions[] = 'filename LIKE ?';
            $params[] = '%.' . Like::escape(ltrim((string) $filters['extension'], '.'));
        }

        return [implode(' AND ', $conditions), $params];
    }
}
