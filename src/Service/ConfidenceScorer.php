<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Audit\AuditLogger;
use Oronts\AssetPilotBundle\Enum\ConfidenceLevel;

class ConfidenceScorer
{
    public const int RECENTLY_UPLOADED_DAYS = 30;
    public const int PROBABLY_UNUSED_DAYS = 90;

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
            return ConfidenceLevel::Protected->value;
        }

        if (in_array((int) $item['id'], $historicalIds, true)) {
            return ConfidenceLevel::HistoricallyUsed->value;
        }

        $modified = !empty($item['modified_at']) ? new \DateTimeImmutable($item['modified_at']) : null;

        if ($modified === null) {
            return ConfidenceLevel::ProbablyUnused->value;
        }

        $daysAgo = (int) $now->diff($modified)->days;

        if ($daysAgo < self::RECENTLY_UPLOADED_DAYS) {
            return ConfidenceLevel::RecentlyUploaded->value;
        }

        if ($daysAgo < self::PROBABLY_UNUSED_DAYS) {
            return ConfidenceLevel::ProbablyUnused->value;
        }

        return ConfidenceLevel::DefinitelyUnused->value;
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
