<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Event\AssetMutationEvent;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Pimcore\Model\Asset;
use Pimcore\Model\Element\Tag;
use Pimcore\Model\Exception\NotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class AssetMetadataMutationService implements AssetMetadataMutationServiceInterface
{
    use MapsObserverDeliveryWarnings;

    public function __construct(
        private readonly LoopGuard $loopGuard,
        private readonly ReviewedAssetLockCoordinator $reviewedLocks,
        private readonly ElementAuthorizationInterface $authorization,
        private readonly AssetPropertyServiceInterface $propertyService,
        private readonly AssetMetadataFingerprintService $fingerprints,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly string $lockProperty,
    ) {}

    /** @param list<int> $assetIds @param list<int> $tagIds */
    public function tagPlan(ActorContext $actor, array $assetIds, array $tagIds, bool $replace): ApplyPlan
    {
        sort($assetIds, SORT_NUMERIC);
        sort($tagIds, SORT_NUMERIC);
        $this->assertRequestedTagsExist($tagIds, false);

        return new ApplyPlan(
            kind: 'asset-bulk-tag',
            actor: $actor,
            request: ['assetIds' => $assetIds, 'replace' => $replace, 'tagIds' => $tagIds],
            config: $this->fingerprints->planConfig(),
            targets: $this->fingerprints->tagTargets($assetIds),
        );
    }

    /** @param list<int> $assetIds */
    public function propertyPlan(
        ActorContext $actor,
        array $assetIds,
        string $name,
        string $type,
        string|bool $value,
    ): ApplyPlan {
        sort($assetIds, SORT_NUMERIC);

        return new ApplyPlan(
            kind: 'asset-bulk-property',
            actor: $actor,
            request: ['assetIds' => $assetIds, 'data' => $value, 'name' => $name, 'type' => $type],
            config: $this->fingerprints->planConfig(),
            targets: $this->fingerprints->propertyTargets($assetIds, $name),
        );
    }

    /**
     * @param list<int> $assetIds
     * @param list<int> $tagIds
     * @param array<string, string> $expectedFingerprints
     *
     * @return array{tagged: int, failed: int, errors: array<int|string, string>, observerWarnings: list<string>}
     */
    public function applyTags(array $assetIds, array $tagIds, bool $replace, array $expectedFingerprints): array
    {
        return $this->withLockedAssets(
            $assetIds,
            true,
            fn (Asset $asset) => $this->fingerprints->assertTagsUnchanged($asset, $expectedFingerprints),
            function (array $lockedIds, array $lockedAssets) use ($tagIds, $replace): array {
                unset($lockedAssets);
                sort($tagIds, SORT_NUMERIC);
                $this->assertRequestedTagsExist($tagIds, true);
                $this->assignTags($lockedIds, $tagIds, $replace);

                return [
                    'tagged' => count($lockedIds),
                    'failed' => 0,
                    'errors' => [],
                    'observerWarnings' => $this->observerWarnings(
                        new AssetMutationEvent($lockedIds, 'tag', ['tagIds' => $tagIds, 'replace' => $replace]),
                        AssetPilotEvents::ASSETS_TAGGED,
                        'Asset-tag observer delivery failed.',
                        ['asset_ids' => $lockedIds, 'tag_ids' => $tagIds],
                    ),
                ];
            },
        );
    }

    /**
     * @param list<int> $assetIds
     * @param array<string, string> $expectedFingerprints
     * @return array{updated: int, failed: int, errors: array<int|string, string>, observerWarnings: list<string>}
     */
    public function applyProperty(
        array $assetIds,
        string $name,
        string $type,
        string|bool $value,
        array $expectedFingerprints,
    ): array {
        return $this->withLockedAssets(
            $assetIds,
            false,
            fn (Asset $asset) => $this->fingerprints->assertPropertyUnchanged($asset, $name, $expectedFingerprints),
            function (array $lockedIds, array $lockedAssets) use ($name, $type, $value): array {
                unset($lockedIds);

                return $this->propertyService->bulkSetPropertyOnLockedAssets($lockedAssets, $name, $type, $value);
            },
        );
    }

    protected function loadAsset(int $assetId): ?Asset
    {
        return Asset::getById($assetId, ['force' => true]);
    }

    protected function loadTag(int $tagId, bool $force = false): ?Tag
    {
        if (!$force) {
            return Tag::getById($tagId);
        }

        $tag = new Tag();
        try {
            $tag->getDao()->getById($tagId);

            return $tag;
        } catch (NotFoundException) {
            return null;
        }
    }

    /** @param list<int> $assetIds @param list<int> $tagIds */
    protected function assignTags(array $assetIds, array $tagIds, bool $replace): void
    {
        Tag::batchAssignTagsToElement('asset', $assetIds, $tagIds, $replace);
    }

    /**
     * @param list<int> $assetIds
     * @param callable(Asset): void $validate
     * @param callable(list<int>, list<Asset>): mixed $mutation
     */
    private function withLockedAssets(array $assetIds, bool $allowFolders, callable $validate, callable $mutation): mixed
    {
        return $this->reviewedLocks->run(
            $assetIds,
            static fn (int $assetId): \Throwable => new StaleApplyPlanException(sprintf('Asset %d is busy. Preview the operation again.', $assetId)),
            function (array $lockedIds) use ($allowFolders, $validate, $mutation): mixed {
                $lockedAssets = [];
                foreach ($lockedIds as $assetId) {
                    $asset = $this->loadAsset($assetId);
                    if (!$asset instanceof Asset || (!$allowFolders && $asset instanceof Asset\Folder)) {
                        throw new StaleApplyPlanException(sprintf('Asset %d no longer exists. Preview the operation again.', $assetId));
                    }
                    if (!$this->authorization->isAllowed($asset, 'publish')) {
                        throw new StaleApplyPlanException(sprintf('Asset %d is no longer eligible. Preview the operation again.', $assetId));
                    }
                    if (AssetProtection::isLocked($asset, $this->lockProperty)) {
                        throw new StaleApplyPlanException(sprintf('Asset %d is protected and cannot be modified. Preview the operation again.', $assetId));
                    }

                    $validate($asset);
                    $lockedAssets[] = $asset;
                    $this->loopGuard->refreshAsset($assetId);
                }

                return $mutation($lockedIds, $lockedAssets);
            },
        );
    }

    /** @param list<int> $tagIds */
    private function assertRequestedTagsExist(array $tagIds, bool $stale): void
    {
        foreach ($tagIds as $tagId) {
            if ($this->loadTag($tagId, $stale) instanceof Tag) {
                continue;
            }

            $message = sprintf('Tag %d does not exist.', $tagId);
            if ($stale) {
                throw new StaleApplyPlanException($message . ' Preview the operation again.');
            }

            throw new \InvalidArgumentException($message);
        }
    }
}
