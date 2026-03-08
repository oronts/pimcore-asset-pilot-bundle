<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Audit\AuditLogger;

class ConfidenceScorer
{
    private const int RECENTLY_UPLOADED_DAYS = 30;
    private const int PROBABLY_UNUSED_DAYS = 90;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * Enrich unused asset items with a confidence classification.
     *
     * @param array[] $items UnusedAssetFinder result items
     * @return array[] Items with added 'confidence' key
     */
    public function score(array $items): array
    {
        if (empty($items)) {
            return $items;
        }

        $assetIds = array_column($items, 'id');
        $historicalIds = $this->getAssetsWithAuditHistory($assetIds);

        $now = new \DateTimeImmutable();

        foreach ($items as &$item) {
            $item['confidence'] = $this->classify($item, $historicalIds, $now);
        }

        return $items;
    }

    private function classify(array $item, array $historicalIds, \DateTimeImmutable $now): string
    {
        if (!empty($item['locked'])) {
            return 'protected';
        }

        if (in_array((int) $item['id'], $historicalIds, true)) {
            return 'historically_used';
        }

        $modified = !empty($item['modified_at']) ? new \DateTimeImmutable($item['modified_at']) : null;

        if ($modified === null) {
            return 'probably_unused';
        }

        $daysAgo = (int) $now->diff($modified)->days;

        if ($daysAgo < self::RECENTLY_UPLOADED_DAYS) {
            return 'recently_uploaded';
        }

        if ($daysAgo < self::PROBABLY_UNUSED_DAYS) {
            return 'probably_unused';
        }

        return 'definitely_unused';
    }

    /**
     * @param int[] $assetIds
     * @return int[] Asset IDs that have at least one audit log entry
     */
    private function getAssetsWithAuditHistory(array $assetIds): array
    {
        if (empty($assetIds)) {
            return [];
        }

        try {
            $rows = $this->connection->createQueryBuilder()
                ->select('DISTINCT asset_id')
                ->from(AuditLogger::TABLE_NAME)
                ->where('asset_id IN (:ids)')
                ->setParameter('ids', $assetIds, Connection::PARAM_INT_ARRAY)
                ->executeQuery()
                ->fetchFirstColumn();

            return array_map('intval', $rows);
        } catch (\Throwable) {
            return [];
        }
    }
}
