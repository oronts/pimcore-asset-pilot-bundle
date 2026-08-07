<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\PropertyType;
use Oronts\AssetPilotBundle\Event\AssetMutationEvent;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Support\PropertyValue;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class AssetPropertyService implements AssetPropertyServiceInterface
{
    use MapsObserverDeliveryWarnings;

    public function __construct(
        private readonly LoopGuard $loopGuard,
        private readonly ElementAuthorizationInterface $authorization,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly string $lockProperty = AssetProtection::DEFAULT_LOCK_PROPERTY,
    ) {}

    /** @return list<string> */
    public function lockAsset(int $assetId): array
    {
        $this->setProperty($assetId, $this->lockProperty, PropertyType::Bool->value, '1');
        $this->logger->info('Asset Pilot: locked asset {id}', ['id' => $assetId]);

        return $this->observerWarnings(
            new AssetMutationEvent([$assetId], 'lock', ['property' => $this->lockProperty]),
            AssetPilotEvents::ASSET_LOCKED,
            'Asset-lock observer delivery failed.',
            ['asset_id' => $assetId],
        );
    }

    /** @return list<string> */
    public function unlockAsset(int $assetId): array
    {
        $asset = $this->requireMutableAsset($assetId);
        $this->mutate($asset, fn (Asset $mutable): Asset => $this->removeProperty($mutable, $this->lockProperty));
        $this->logger->info('Asset Pilot: unlocked asset {id}', ['id' => $assetId]);

        return $this->observerWarnings(
            new AssetMutationEvent([$assetId], 'unlock', ['property' => $this->lockProperty]),
            AssetPilotEvents::ASSET_UNLOCKED,
            'Asset-unlock observer delivery failed.',
            ['asset_id' => $assetId],
        );
    }

    public function setProperty(int $assetId, string $name, string $type, string $data): void
    {
        [$propertyType, $value] = $this->validatedProperty($name, $type, $data);
        $asset = $this->requireMutableAsset($assetId);
        $this->mutate($asset, static fn (Asset $mutable): Asset => $mutable->setProperty($name, $propertyType->value, $value));
    }

    public function setPropertyOnLockedAsset(Asset $asset, string $name, string $type, string $data): void
    {
        if (!$this->authorization->isAllowed($asset, 'publish')) {
            throw new \RuntimeException('Asset mutation is not permitted.');
        }

        [$propertyType, $value] = $this->validatedProperty($name, $type, $data);
        $asset->setProperty($name, $propertyType->value, $value);
        $this->saveAsset($asset);
    }

    /**
     * @param list<Asset> $assets
     * @return array{updated: int, failed: int, errors: array<int|string, string>, observerWarnings: list<string>}
     */
    public function bulkSetPropertyOnLockedAssets(
        array $assets,
        string $name,
        string $type,
        string|bool $value,
    ): array {
        $updatedIds = [];
        $failed = 0;
        $errors = [];

        if ($type === PropertyType::Bool->value) {
            $value = PropertyValue::normalize(PropertyType::Bool, $value);
        }

        foreach ($assets as $asset) {
            $id = (int) $asset->getId();
            $marked = false;

            try {
                $this->loopGuard->markAssetProcessing($id);
                $marked = true;
                $this->loopGuard->refreshAsset($id);
                $propertyValue = $type === PropertyType::Bool->value ? ($value ? '1' : '0') : (string) $value;
                $this->setPropertyOnLockedAsset($asset, $name, $type, $propertyValue);
                $updatedIds[] = $id;
            } catch (\Throwable $e) {
                $errors[$id] = 'Failed to update the asset property.';
                $failed++;
                $this->logger->error('Asset Pilot: failed to set property on asset {id}', [
                    'id' => $id,
                    'name' => $name,
                    'exception' => $e,
                ]);
            } finally {
                if ($marked) {
                    $this->loopGuard->unmarkAssetProcessing($id);
                }
            }
        }

        return $this->bulkPropertyResult(
            array_map(static fn (Asset $asset): int => (int) $asset->getId(), $assets),
            $updatedIds,
            $failed,
            $errors,
            $name,
            $type,
        );
    }

    /**
     * @param list<int> $assetIds
     * @param list<int> $updatedIds
     * @param array<int|string, string> $errors
     * @return array{updated: int, failed: int, errors: array<int|string, string>, observerWarnings: list<string>}
     */
    private function bulkPropertyResult(
        array $assetIds,
        array $updatedIds,
        int $failed,
        array $errors,
        string $name,
        string $type,
    ): array {
        $this->logger->info('Asset Pilot: bulk property "{name}" set on {updated}/{total} assets', [
            'name' => $name,
            'updated' => count($updatedIds),
            'total' => count($assetIds),
        ]);

        $observerWarnings = $updatedIds === [] ? [] : $this->observerWarnings(
            new AssetMutationEvent($updatedIds, 'property', ['name' => $name, 'type' => $type]),
            AssetPilotEvents::ASSET_PROPERTY_SET,
            'Asset-property observer delivery failed.',
            ['asset_ids' => $updatedIds, 'property' => $name],
        );

        return [
            'updated' => count($updatedIds),
            'failed' => $failed,
            'errors' => $errors,
            'observerWarnings' => $observerWarnings,
        ];
    }

    private function requireMutableAsset(int $assetId): Asset
    {
        $asset = $this->loadAsset($assetId);
        if ($asset === null || $asset instanceof Asset\Folder) {
            throw new \InvalidArgumentException('Asset not found.');
        }
        if (!$this->authorization->isAllowed($asset, 'publish')) {
            throw new \RuntimeException('Asset mutation is not permitted.');
        }

        return $asset;
    }

    private function mutate(Asset $asset, callable $mutation): void
    {
        $assetId = (int) $asset->getId();
        if (!$this->loopGuard->acquireAsset($assetId)) {
            throw new \RuntimeException('Asset is busy.');
        }

        $this->loopGuard->markAssetProcessing($assetId);
        try {
            $lockedAsset = $this->loadAsset($assetId);
            if ($lockedAsset === null || $lockedAsset instanceof Asset\Folder) {
                throw new \InvalidArgumentException('Asset not found.');
            }
            if (!$this->authorization->isAllowed($lockedAsset, 'publish')) {
                throw new \RuntimeException('Asset mutation is not permitted.');
            }

            $mutation($lockedAsset);
            $this->loopGuard->refreshAsset($assetId);
            $this->saveAsset($lockedAsset);
        } finally {
            $this->loopGuard->unmarkAssetProcessing($assetId);
            $this->loopGuard->releaseAsset($assetId);
        }
    }

    private function removeProperty(Asset $asset, string $name): Asset
    {
        $asset->removeProperty($name);

        return $asset;
    }

    private function isValidPropertyName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_.:-]{1,190}$/D', $name) === 1;
    }

    /** @return array{PropertyType, string|bool} */
    private function validatedProperty(string $name, string $type, string $data): array
    {
        $propertyType = PropertyType::tryFrom($type);
        if ($propertyType === null) {
            throw new \InvalidArgumentException('Unsupported asset property type.');
        }
        if (!$this->isValidPropertyName($name)) {
            throw new \InvalidArgumentException('Invalid asset property name.');
        }

        $value = PropertyValue::normalize($propertyType, $data);

        return [$propertyType, $value];
    }

    protected function loadAsset(int $assetId): ?Asset
    {
        return Asset::getById($assetId);
    }

    protected function saveAsset(Asset $asset): void
    {
        $asset->save();
    }
}
