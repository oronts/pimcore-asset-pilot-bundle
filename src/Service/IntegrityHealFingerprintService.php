<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Integrity\CompositeIntegrityChecker;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Model\HealResult;
use Pimcore\Model\Asset;
use Pimcore\Model\Version;

class IntegrityHealFingerprintService
{
    private const int VERSION = 1;

    /** @param array<string, mixed> $planConfiguration */
    public function __construct(
        private readonly CompositeIntegrityChecker $checker,
        private readonly array $planConfiguration,
        private readonly string $lockProperty = AssetProtection::DEFAULT_LOCK_PROPERTY,
    ) {}

    /** @return array<string, mixed> */
    public function planConfig(): array
    {
        return [
            'version' => self::VERSION,
            'integrity' => $this->planConfiguration['integrity'] ?? [],
            'protection' => [
                'exclude_folders' => $this->planConfiguration['protection']['exclude_folders'] ?? [],
                'lock_property' => $this->lockProperty,
            ],
            'quarantine' => $this->planConfiguration['quarantine'] ?? [],
            'content_scan' => $this->planConfiguration['content_scan'] ?? [],
            'notifications' => $this->planConfiguration['notifications'] ?? [],
        ];
    }

    /** @param list<int> $assetIds @return array<string, string> */
    public function fingerprintMap(array $assetIds): array
    {
        sort($assetIds, SORT_NUMERIC);
        $fingerprints = [];

        foreach ($assetIds as $assetId) {
            $fingerprints[$this->targetId($assetId)] = $this->stateFingerprint($this->loadAsset($assetId));
        }

        return $fingerprints;
    }

    /**
     * @param list<int> $assetIds
     * @param array<string, string> $stateFingerprints
     * @param array<int, HealResult> $previewResults
     * @return list<ApplyPlanTarget>
     */
    public function targets(array $assetIds, array $stateFingerprints, array $previewResults): array
    {
        sort($assetIds, SORT_NUMERIC);
        $targets = [];

        foreach ($assetIds as $assetId) {
            $targetId = $this->targetId($assetId);
            if (!isset($stateFingerprints[$targetId], $previewResults[$assetId])) {
                throw new \InvalidArgumentException(sprintf('Missing integrity preview state for asset %d.', $assetId));
            }

            $targets[] = new ApplyPlanTarget(
                $targetId,
                $this->combinedFingerprint($stateFingerprints[$targetId], $previewResults[$assetId]),
            );
        }

        return $targets;
    }

    public function assertUnchanged(
        int $assetId,
        ?Asset $asset,
        HealResult $previewResult,
        string $expectedFingerprint,
    ): void {
        $current = $this->combinedFingerprint($this->stateFingerprint($asset), $previewResult);
        if (!hash_equals($expectedFingerprint, $current)) {
            throw new StaleApplyPlanException(sprintf(
                'Asset %d changed after the integrity preview. Preview the heal again before applying.',
                $assetId,
            ));
        }
    }

    protected function loadAsset(int $assetId): ?Asset
    {
        return Asset::getById($assetId, ['force' => true]);
    }

    /** @return list<Version> */
    protected function loadVersions(Asset $asset): array
    {
        return $asset->getVersions();
    }

    private function stateFingerprint(?Asset $asset): string
    {
        if (!$asset instanceof Asset || $asset instanceof Asset\Folder) {
            return $this->hash(['exists' => false]);
        }

        $checker = $this->checker->resolve($asset);
        $checkerResult = $checker?->check($asset);

        return $this->hash([
            'checker' => $checker === null ? null : [
                'class' => $checker::class,
                'priority' => $checker->priority(),
                'result' => [
                    'checker' => $checkerResult?->checker,
                    'reason' => $checkerResult?->reason,
                    'status' => $checkerResult?->status->value,
                ],
            ],
            'checksum' => $asset->getChecksum(),
            'exists' => true,
            'locked' => AssetProtection::isLocked($asset, $this->lockProperty),
            'modifiedAt' => $asset->getModificationDate(),
            'path' => $asset->getRealFullPath(),
            'versions' => array_map($this->versionState(...), $this->loadVersions($asset)),
        ]);
    }

    /** @return array<string, int|string|null> */
    private function versionState(Version $version): array
    {
        return [
            'id' => $version->getId(),
            'cid' => $version->getCid(),
            'date' => $version->getDate(),
            'binaryFileHash' => $version->getBinaryFileHash(),
            'binaryFileId' => $version->getBinaryFileId(),
            'storageType' => $version->getStorageType(),
        ];
    }

    private function combinedFingerprint(string $stateFingerprint, HealResult $previewResult): string
    {
        return $this->hash([
            'preview' => [
                'checker' => $previewResult->checker,
                'dryRun' => $previewResult->dryRun,
                'observerWarnings' => $previewResult->observerWarnings,
                'outcome' => $previewResult->outcome->value,
                'reason' => $previewResult->reason,
                'toVersion' => $previewResult->toVersion,
            ],
            'state' => $stateFingerprint,
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

    private function targetId(int $assetId): string
    {
        return 'asset:' . $assetId;
    }
}
