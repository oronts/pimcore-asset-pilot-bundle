<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Query;

/**
 * The sort allowlist shared by every service that lists rows from the `assets` table (alias `a`),
 * so the unused-assets and asset-management tabs sort identically. file_size is intentionally
 * absent: it is not a DB column, so it cannot be sorted server-side and falls back to DEFAULT.
 */
class AssetSortColumns
{
    public const string DEFAULT = 'modified_at';

    /** @var array<string, string> public sort key => column */
    public const array MAP = [
        'id' => 'a.id',
        'filename' => 'a.filename',
        'type' => 'a.type',
        'modified_at' => 'a.modificationDate',
    ];
}
