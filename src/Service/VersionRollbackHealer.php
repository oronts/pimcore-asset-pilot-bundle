<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\HealOutcome;
use Oronts\AssetPilotBundle\Event\AssetHealEvent;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Integrity\CompositeIntegrityChecker;
use Oronts\AssetPilotBundle\Model\HealResult;
use Oronts\AssetPilotBundle\Notification\NotificationDispatcher;
use Pimcore\Model\Asset;
use Pimcore\Model\Version;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Self-heal for a broken asset binary: walk versions newest-to-oldest, find the first that renders
 * (probed from the version's stored bytes, never by mutating the live asset), then restore the live
 * binary from it. The restore save is LoopGuard-wrapped so the resulting Asset events do not
 * re-enter the organize pipeline. Each heal is logged so it can be undone (a renderable version can
 * still be the wrong content). When nothing renders the asset is reported (and optionally
 * quarantined) for human review, never silently left or destroyed.
 */
class VersionRollbackHealer
{
    public function __construct(
        protected readonly CompositeIntegrityChecker $checker,
        protected readonly LoopGuard $loopGuard,
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly IntegrityHealLog $healLog,
        protected readonly LoggerInterface $logger,
        protected readonly ?QuarantineService $quarantine = null,
        protected readonly string $onUnrecoverable = 'report',
        protected readonly ?NotificationDispatcher $notifier = null,
    ) {}

    public function healById(int $assetId, bool $dryRun = false): HealResult
    {
        $asset = $this->loadAsset($assetId);
        if ($asset === null || $asset instanceof Asset\Folder) {
            return new HealResult(HealOutcome::Skipped, 'none', null, 'Asset not found.', $dryRun);
        }

        return $this->heal($asset, $dryRun);
    }

    public function heal(Asset $asset, bool $dryRun = false): HealResult
    {
        $checker = $this->checker->resolve($asset);
        if ($checker === null) {
            return new HealResult(HealOutcome::Unverifiable, 'none', null, 'No integrity checker supports this asset.', $dryRun);
        }

        $live = $checker->check($asset);
        if ($live->isRenderable()) {
            return new HealResult(HealOutcome::AlreadyRenderable, $live->checker, null, null, $dryRun);
        }
        if (!$live->isBroken()) {
            // Unverifiable: never heal what we cannot confirm is broken.
            return new HealResult(HealOutcome::Unverifiable, $live->checker, null, $live->reason, $dryRun);
        }

        $extension = strtolower(pathinfo((string) $asset->getFilename(), PATHINFO_EXTENSION));
        $versions = $this->newestFirstVersions($asset);
        $preHealVersion = $versions === [] ? null : (int) $versions[0]->getId();

        foreach ($versions as $version) {
            $binary = $this->versionBinary($version);
            if ($binary === null || $binary === '') {
                continue;
            }
            if (!$checker->checkBinary($binary, $extension)->isRenderable()) {
                continue;
            }

            $toVersion = (int) $version->getId();
            if ($dryRun) {
                return new HealResult(HealOutcome::Healed, $live->checker, $toVersion, null, true);
            }

            $preHeal = new AssetHealEvent($asset, $toVersion);
            $this->eventDispatcher->dispatch($preHeal, AssetPilotEvents::INTEGRITY_PRE_HEAL);
            if ($preHeal->isCancelled()) {
                return new HealResult(HealOutcome::Skipped, $live->checker, $toVersion, 'Heal cancelled by a listener.');
            }

            $this->restore($asset, $version);
            $this->healLog->record((int) $asset->getId(), $preHealVersion, $toVersion, $live->checker, IntegrityHealLog::STATUS_HEALED);
            $this->eventDispatcher->dispatch(new AssetHealEvent($asset, $toVersion, HealOutcome::Healed), AssetPilotEvents::INTEGRITY_POST_HEAL);

            return new HealResult(HealOutcome::Healed, $live->checker, $toVersion);
        }

        if (!$dryRun) {
            $firstUnrecoverable = $this->healLog->latestStatus((int) $asset->getId()) !== IntegrityHealLog::STATUS_UNRECOVERABLE;
            $this->healLog->record((int) $asset->getId(), null, null, $live->checker, IntegrityHealLog::STATUS_UNRECOVERABLE);
            $this->routeUnrecoverable($asset, $firstUnrecoverable);
        }

        return new HealResult(HealOutcome::Unrecoverable, $live->checker, null, 'No renderable version to roll back to.', $dryRun);
    }

    /**
     * Reverse the most recent heal of an asset: restore the pre-heal version. Returns false when
     * there is nothing undoable (no heal, or its pre-heal version is gone).
     */
    public function undo(int $assetId): bool
    {
        $entry = $this->healLog->findUndoable($assetId);
        if ($entry === null || $entry['from_version'] === null) {
            return false;
        }

        $asset = $this->loadAsset($assetId);
        $version = $this->loadVersion($entry['from_version']);
        if ($asset === null || $asset instanceof Asset\Folder || $version === null) {
            return false;
        }

        $this->restore($asset, $version);
        $this->healLog->markUndone($entry['id']);

        return true;
    }

    protected function restore(Asset $asset, Version $version): void
    {
        $assetId = (int) $asset->getId();
        if ((int) $version->getCid() !== $assetId) {
            throw new \RuntimeException(sprintf('Version %d does not belong to asset %d.', (int) $version->getId(), $assetId));
        }

        $stream = $this->versionStream($version);
        if (!is_resource($stream)) {
            throw new \RuntimeException(sprintf('Version %d has no readable binary.', (int) $version->getId()));
        }

        // LoopGuard window: mark processing before the save (so the AssetUploadListener short-circuits
        // its own postUpdate) and recently-moved after, mirroring the move pipeline's guarded save.
        $this->loopGuard->markAssetProcessing($assetId);
        try {
            $asset->setStream($stream);
            $asset->save(['versionNote' => 'asset-pilot integrity heal: rollback to version ' . $version->getId()]);
            $this->loopGuard->markAssetRecentlyMoved($assetId);
        } finally {
            $this->loopGuard->unmarkAssetProcessing($assetId);
        }
    }

    private function routeUnrecoverable(Asset $asset, bool $notify): void
    {
        $this->logger->warning('Asset Pilot: asset {id} is broken and has no renderable version to roll back to.', [
            'id' => $asset->getId(),
        ]);

        // Best-effort quarantine: QuarantineService re-verifies the asset is unused, so a still-referenced
        // broken asset stays put and is only reported (the report row is already written above).
        $quarantined = false;
        if ($this->onUnrecoverable === 'quarantine' && $this->quarantine !== null) {
            $result = $this->quarantine->quarantine([(int) $asset->getId()]);
            $quarantined = ($result['quarantined'] ?? 0) > 0;
        }

        if (!$notify) {
            return;
        }

        $this->notifier?->dispatch(
            'Asset Pilot: unrecoverable broken asset',
            sprintf(
                'Asset %d (%s) is broken and no stored version renders. %s',
                $asset->getId(),
                $asset->getRealFullPath(),
                $quarantined ? 'It was moved to quarantine for review.' : 'It was left in place and reported for review.',
            ),
        );
    }

    /**
     * @return list<Version> newest-first (Asset::getVersions() is oldest-first)
     */
    protected function newestFirstVersions(Asset $asset): array
    {
        return array_reverse($asset->getVersions());
    }

    protected function versionBinary(Version $version): ?string
    {
        $stream = $this->versionStream($version);
        if (!is_resource($stream)) {
            return null;
        }

        try {
            $binary = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        return is_string($binary) ? $binary : null;
    }

    /**
     * @return resource|null the version's stored binary, side-effect free (no asset reconstruction)
     */
    protected function versionStream(Version $version)
    {
        $stream = $version->getBinaryFileStream();

        return is_resource($stream) ? $stream : null;
    }

    protected function loadAsset(int $assetId): ?Asset
    {
        return Asset::getById($assetId);
    }

    protected function loadVersion(int $versionId): ?Version
    {
        return Version::getById($versionId);
    }
}
