<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Query;

use Doctrine\DBAL\Query\QueryBuilder;
use Oronts\AssetPilotBundle\Installer;

class IndexedAssetSize
{
    public static function join(QueryBuilder $query, string $assetAlias = 'a', string $sizeAlias = 'asset_size'): void
    {
        $query
            ->addSelect(sprintf('%s.file_size AS indexed_file_size', $sizeAlias))
            ->addSelect(sprintf('%s.size_known AS indexed_size_known', $sizeAlias))
            ->addSelect(sprintf('%s.indexed_at AS size_indexed_at', $sizeAlias))
            ->leftJoin($assetAlias, Installer::TABLE_CHECKSUM, $sizeAlias, sprintf('%s.asset_id = %s.id', $sizeAlias, $assetAlias));
    }

    public static function bytes(array $row): ?int
    {
        if (!(bool) ($row['indexed_size_known'] ?? false) || !isset($row['indexed_file_size'], $row['size_indexed_at'])) {
            return null;
        }
        $indexedAt = strtotime((string) $row['size_indexed_at']);
        $modifiedAt = (int) ($row['modified_at'] ?? 0);
        if ($indexedAt === false || $indexedAt < $modifiedAt) {
            return null;
        }

        return (int) $row['indexed_file_size'];
    }

    private function __construct() {}
}
