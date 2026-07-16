<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Service\Query\PimcoreSchema;
use Pimcore\Model\Asset;
use Symfony\Contracts\Service\ResetInterface;

class AssetMutationFingerprintService implements ResetInterface
{
    private const int VERSION = 1;

    public function __construct(
        private readonly Connection $connection,
        private readonly ContentUsageScanner $contentScanner,
        private readonly DependencyUsageScannerInterface $dependencyScanner,
        private readonly string $lockProperty = AssetProtection::DEFAULT_LOCK_PROPERTY,
    ) {}

    /** @param list<int> $assetIds @return list<ApplyPlanTarget> */
    public function targets(array $assetIds): array
    {
        $fingerprints = $this->fingerprintMap($assetIds);

        return array_map(
            static fn (string $id, string $fingerprint): ApplyPlanTarget => new ApplyPlanTarget($id, $fingerprint),
            array_keys($fingerprints),
            array_values($fingerprints),
        );
    }

    /** @param list<int> $assetIds @return array<string, string> */
    public function fingerprintMap(array $assetIds): array
    {
        $fingerprints = [];
        foreach ($assetIds as $assetId) {
            $fingerprints['asset:' . $assetId] = $this->fingerprint($assetId);
        }
        ksort($fingerprints, SORT_STRING);

        return $fingerprints;
    }

    /** @return array{version: int, lockProperty: string, contentVerification: bool} */
    public function planConfig(): array
    {
        return [
            'version' => self::VERSION,
            'lockProperty' => $this->lockProperty,
            'contentVerification' => $this->contentScanner->canVerify(),
        ];
    }

    public function reset(): void
    {
        $this->contentScanner->reset();
        if ($this->dependencyScanner instanceof ResetInterface) {
            $this->dependencyScanner->reset();
        }
    }

    /** @param array<string, string> $expectedFingerprints */
    public function assertUnchanged(int $assetId, array $expectedFingerprints): void
    {
        $key = 'asset:' . $assetId;
        if (!isset($expectedFingerprints[$key]) || !hash_equals($expectedFingerprints[$key], $this->fingerprint($assetId))) {
            throw new StaleApplyPlanException(sprintf(
                'Asset %d changed after preview. Preview the operation again before applying.',
                $assetId,
            ));
        }
    }

    protected function loadAsset(int $assetId): ?Asset
    {
        return Asset::getById($assetId, ['force' => true]);
    }

    /** @return list<array{sourcetype: mixed, sourceid: mixed}> */
    protected function dependencyRows(int $assetId): array
    {
        return $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT sourcetype, sourceid FROM %s WHERE targettype = ? AND targetid = ? ORDER BY sourcetype ASC, sourceid ASC',
                PimcoreSchema::TABLE_DEPENDENCIES,
            ),
            [PimcoreSchema::ELEMENT_TYPE_ASSET, $assetId],
        );
    }

    private function fingerprint(int $assetId): string
    {
        $asset = $this->loadAsset($assetId);
        if (!$asset instanceof Asset || $asset instanceof Asset\Folder) {
            return $this->hash(['exists' => false]);
        }

        return $this->hash([
            'contentReferenced' => $this->contentScanner->isReferencedInContent($asset),
            'dependencies' => $this->dependencyRows($assetId),
            'exists' => true,
            'liveDependencyReferenced' => $this->dependencyScanner->isReferenced($asset),
            'locked' => AssetProtection::isLocked($asset, $this->lockProperty),
            'modifiedAt' => $asset->getModificationDate(),
            'path' => $asset->getRealFullPath(),
        ]);
    }

    /** @param array<string, mixed> $snapshot */
    private function hash(array $snapshot): string
    {
        return hash('sha256', json_encode(
            $snapshot,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
