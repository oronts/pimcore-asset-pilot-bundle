<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api\Support;

use Oronts\AssetPilotBundle\Api\Serialization\ApiDateFormatterInterface;

/**
 * Normalizes the timestamp columns of an audit-log row to RFC 3339 UTC.
 *
 * Shared by every endpoint that emits `AuditEntry` rows (the audit list, the dashboard, and the
 * operation-status feed) so the OpenAPI `date-time` contract is honored uniformly rather than
 * re-implemented per controller. The heavy lifting stays in {@see ApiDateFormatterInterface}; this
 * is only the audit-row convenience over it.
 */
class AuditRowDates
{
    private const array TIMESTAMP_COLUMNS = ['created_at', 'updated_at', 'committed_at'];

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $row, ApiDateFormatterInterface $dates): array
    {
        foreach (self::TIMESTAMP_COLUMNS as $column) {
            if (!array_key_exists($column, $row)) {
                continue;
            }

            $value = $row[$column];
            $row[$column] = $dates->fromDatabase($value === null ? null : (string) $value);
        }

        return $row;
    }
}
