<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Query;

/**
 * Normalize a raw asset-index row into the shared API/CSV shape: resolve the indexed byte size, ISO-8601 the
 * timestamps, build the full path, cast the lock flag, and drop the internal indexed_* columns. Shared by the
 * search and unused-asset hydrators so both emit the identical row shape.
 */
final class AssetRow
{
    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function normalize(array $item): array
    {
        $item['file_size'] = IndexedAssetSize::bytes($item);
        $item['size_known'] = $item['file_size'] !== null;
        $item['created_at'] = $item['created_at'] ? gmdate(\DateTimeInterface::ATOM, (int) $item['created_at']) : null;
        $item['modified_at'] = $item['modified_at'] ? gmdate(\DateTimeInterface::ATOM, (int) $item['modified_at']) : null;
        $item['full_path'] = rtrim((string) ($item['path'] ?? ''), '/') . '/' . ($item['filename'] ?? '');
        $item['locked'] = (bool) ($item['locked'] ?? false);
        unset($item['indexed_file_size'], $item['indexed_size_known'], $item['size_indexed_at']);

        return $item;
    }
}
