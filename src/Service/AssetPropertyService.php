<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;

class AssetPropertyService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly string $lockProperty = 'asset_pilot_locked',
    ) {}

    public function lockAsset(int $assetId, string $assetPath): void
    {
        $this->setProperty($assetId, $assetPath, $this->lockProperty, 'bool', '1');
        $this->logger->info('Asset Pilot: locked asset {id}', ['id' => $assetId]);
    }

    public function unlockAsset(int $assetId): void
    {
        $this->connection->delete('properties', [
            'cid' => $assetId,
            'ctype' => 'asset',
            'name' => $this->lockProperty,
        ]);
        $this->logger->info('Asset Pilot: unlocked asset {id}', ['id' => $assetId]);
    }

    public function setProperty(int $assetId, string $assetPath, string $name, string $type, string $data): void
    {
        $this->connection->executeStatement(
            'INSERT INTO properties (cid, ctype, cpath, name, type, data, inheritable) VALUES (:cid, :ctype, :cpath, :name, :type, :data, 0)
             ON DUPLICATE KEY UPDATE data = :data, type = :type',
            [
                'cid' => $assetId,
                'ctype' => 'asset',
                'cpath' => $assetPath,
                'name' => $name,
                'type' => $type,
                'data' => $data,
            ],
        );
    }

    /**
     * @param int[] $assetIds
     * @return array{updated: int, failed: int, errors: array<int|string, string>}
     */
    public function bulkSetProperty(array $assetIds, string $name, string $type, string|bool $value): array
    {
        $updated = 0;
        $failed = 0;
        $errors = [];

        if ($type === 'bool') {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        foreach ($assetIds as $id) {
            try {
                $asset = Asset::getById($id);
                if ($asset === null) {
                    $errors[$id] = 'Asset not found';
                    $failed++;
                    continue;
                }

                $dbValue = $type === 'bool' ? ($value ? '1' : '0') : (string) $value;
                $this->setProperty($id, $asset->getRealFullPath(), $name, $type, $dbValue);
                $updated++;
            } catch (\Throwable $e) {
                $errors[$id] = $e->getMessage();
                $failed++;
            }
        }

        $this->logger->info('Asset Pilot: bulk property "{name}" set on {updated}/{total} assets', [
            'name' => $name,
            'updated' => $updated,
            'total' => count($assetIds),
        ]);

        return ['updated' => $updated, 'failed' => $failed, 'errors' => $errors];
    }
}
