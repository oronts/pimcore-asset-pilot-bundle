<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\HealOutcome;
use Oronts\AssetPilotBundle\Enum\UndoHealOutcome;
use Oronts\AssetPilotBundle\Enum\UndoHealReason;
use Oronts\AssetPilotBundle\Event\AssetHealEvent;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Event\NonFatalEventDispatcher;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Integrity\CompositeIntegrityChecker;
use Oronts\AssetPilotBundle\Integrity\IntegrityCheckerInterface;
use Oronts\AssetPilotBundle\Model\HealResult;
use Oronts\AssetPilotBundle\Model\IntegrityResult;
use Oronts\AssetPilotBundle\Model\UndoHealResult;
use Oronts\AssetPilotBundle\Notification\NotificationDispatcher;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
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
        protected readonly ElementAuthorization $authorization,
        protected readonly ?QuarantineService $quarantine = null,
        protected readonly string $onUnrecoverable = 'report',
        protected readonly ?NotificationDispatcher $notifier = null,
        protected readonly array $excludeFolders = [],
        protected readonly string $lockProperty = AssetProtection::DEFAULT_LOCK_PROPERTY,
        protected readonly ?IntegrityHealFingerprintService $healFingerprints = null,
    ) {}

    public function healById(int $assetId, bool $dryRun = false, ?string $expectedFingerprint = null): HealResult
    {
        if ($expectedFingerprint !== null) {
            return $this->healPlanned($assetId, $expectedFingerprint);
        }

        $asset = $this->loadAsset($assetId);
        if ($asset === null || $asset instanceof Asset\Folder) {
            return new HealResult(HealOutcome::Skipped, 'none', null, 'Asset not found.', $dryRun);
        }

        return $this->heal($asset, $dryRun);
    }

    public function previewById(int $assetId): HealResult
    {
        return $this->healById($assetId, true);
    }

    /**
     * @param list<int> $assetIds
     * @param array<string, string> $expectedFingerprints
     * @return array<int, HealResult>
     */
    public function healPlannedBatch(array $assetIds, array $expectedFingerprints): array
    {
        if ($this->healFingerprints === null) {
            throw new StaleApplyPlanException('Integrity plan validation is unavailable. Preview the heal again.');
        }

        sort($assetIds, SORT_NUMERIC);
        $lockedIds = $this->acquirePlannedAssetLocks($assetIds);

        try {
            $this->validatePlannedBatch($assetIds, $lockedIds, $expectedFingerprints);

            return $this->applyPlannedBatch($assetIds, $lockedIds, $expectedFingerprints);
        } finally {
            $this->releaseAssetLocks($lockedIds);
        }
    }

    /**
     * @param list<int> $assetIds
     * @return list<int>
     */
    private function acquirePlannedAssetLocks(array $assetIds): array
    {
        $lockedIds = [];
        try {
            foreach ($assetIds as $assetId) {
                if (!$this->loopGuard->acquireAsset($assetId)) {
                    throw new StaleApplyPlanException(sprintf('Asset %d is being processed. Preview the heal again.', $assetId));
                }
                $lockedIds[] = $assetId;
            }

            return $lockedIds;
        } catch (\Throwable $e) {
            $this->releaseAssetLocks($lockedIds);

            throw $e;
        }
    }

    /**
     * @param list<int> $assetIds
     * @param list<int> $lockedIds
     * @param array<string, string> $expectedFingerprints
     */
    private function validatePlannedBatch(array $assetIds, array $lockedIds, array $expectedFingerprints): void
    {
        foreach ($assetIds as $assetId) {
            $this->refreshAssetLocks($lockedIds);
            $expected = $this->expectedFingerprint($assetId, $expectedFingerprints);
            $preview = $this->previewLockedById($assetId);
            $this->healFingerprints?->assertUnchanged(
                $assetId,
                $this->loadAsset($assetId),
                $preview,
                $expected,
            );
        }
    }

    /**
     * @param list<int> $assetIds
     * @param list<int> $lockedIds
     * @param array<string, string> $expectedFingerprints
     * @return array<int, HealResult>
     */
    private function applyPlannedBatch(array $assetIds, array $lockedIds, array $expectedFingerprints): array
    {
        $results = [];
        foreach ($assetIds as $assetId) {
            $this->refreshAssetLocks($lockedIds);
            $results[$assetId] = $this->healById(
                $assetId,
                false,
                $this->expectedFingerprint($assetId, $expectedFingerprints),
            );
        }

        return $results;
    }

    /** @param array<string, string> $expectedFingerprints */
    private function expectedFingerprint(int $assetId, array $expectedFingerprints): string
    {
        $expected = $expectedFingerprints['asset:' . $assetId] ?? null;
        if (!is_string($expected) || $expected === '') {
            throw new StaleApplyPlanException(sprintf('The integrity plan has no target state for asset %d.', $assetId));
        }

        return $expected;
    }

    /** @param list<int> $assetIds */
    private function releaseAssetLocks(array $assetIds): void
    {
        foreach (array_reverse($assetIds) as $assetId) {
            $this->loopGuard->releaseAsset($assetId);
        }
    }


    public function heal(Asset $asset, bool $dryRun = false): HealResult
    {
        $assetId = (int) $asset->getId();
        if (!$this->loopGuard->acquireAsset($assetId)) {
            return new HealResult(HealOutcome::Skipped, 'none', null, 'Asset is being processed by another job.', $dryRun);
        }

        try {
            $asset = $this->reloadAsset($asset);
            $preflight = $this->preflightResult($asset, $dryRun);
            if ($preflight !== null) {
                return $preflight;
            }

            assert($asset instanceof Asset);

            return $this->healLocked($asset, $dryRun);
        } finally {
            $this->loopGuard->releaseAsset($assetId);
        }
    }

    private function healPlanned(int $assetId, string $expectedFingerprint): HealResult
    {
        if ($this->healFingerprints === null) {
            throw new StaleApplyPlanException('Integrity plan validation is unavailable. Preview the heal again.');
        }
        if (!$this->loopGuard->acquireAsset($assetId)) {
            throw new StaleApplyPlanException(sprintf('Asset %d is being processed. Preview the heal again.', $assetId));
        }

        try {
            $asset = $this->loadAsset($assetId);
            $preflight = $this->preflightResult($asset, false);
            if ($preflight !== null) {
                return $this->checkedResult(
                    $preflight,
                    $assetId,
                    $asset instanceof Asset && !($asset instanceof Asset\Folder) ? $asset : null,
                    $expectedFingerprint,
                );
            }

            assert($asset instanceof Asset);

            return $this->healLocked($asset, false, $expectedFingerprint);
        } finally {
            $this->loopGuard->releaseAsset($assetId);
        }
    }

    private function previewLockedById(int $assetId): HealResult
    {
        $asset = $this->loadAsset($assetId);
        $preflight = $this->preflightResult($asset, true);
        if ($preflight !== null) {
            return $preflight;
        }

        assert($asset instanceof Asset);

        return $this->healLocked($asset, true);
    }

    private function preflightResult(?Asset $asset, bool $dryRun): ?HealResult
    {
        if ($asset === null || $asset instanceof Asset\Folder) {
            return new HealResult(HealOutcome::Skipped, 'none', null, 'Asset not found.', $dryRun);
        }
        if ($dryRun && !$this->authorization->isAllowed($asset, 'view')) {
            return new HealResult(HealOutcome::Skipped, 'none', null, 'Not permitted to inspect this asset.', true);
        }
        if (!$this->authorization->isAllowed($asset, 'publish')) {
            return new HealResult(HealOutcome::Skipped, 'none', null, 'Not permitted to heal this asset.', $dryRun);
        }
        if (AssetProtection::isLocked($asset, $this->lockProperty)) {
            return new HealResult(HealOutcome::Skipped, 'none', null, 'Asset is locked.', $dryRun);
        }
        if ($this->matchingExcludeFolder($asset->getRealFullPath()) !== null) {
            return new HealResult(HealOutcome::Skipped, 'none', null, 'Asset is in an excluded folder.', $dryRun);
        }

        return null;
    }

    /** @param list<int> $assetIds */
    private function refreshAssetLocks(array $assetIds): void
    {
        foreach ($assetIds as $assetId) {
            $this->loopGuard->refreshAsset($assetId);
        }
    }

    private function healLocked(Asset $asset, bool $dryRun, ?string $expectedFingerprint = null): HealResult
    {
        $assetId = (int) $asset->getId();
        $checker = $this->checker->resolve($asset);
        if ($checker === null) {
            return $this->checkedResult(
                new HealResult(HealOutcome::Unverifiable, 'none', null, 'No integrity checker supports this asset.', $dryRun),
                $assetId,
                $asset,
                $expectedFingerprint,
            );
        }

        $live = $checker->check($asset);
        $terminal = $this->terminalLiveResult($live, $dryRun);
        if ($terminal !== null) {
            return $this->checkedResult($terminal, $assetId, $asset, $expectedFingerprint);
        }

        $versions = $this->newestFirstVersions($asset);
        $version = $this->firstRenderableVersion($checker, $versions, $asset);
        if ($version === null) {
            return $this->unrecoverableResult($asset, $live->checker, $dryRun, $expectedFingerprint);
        }

        $preHealVersion = $versions === [] ? null : (int) $versions[0]->getId();

        return $this->healFromVersion($asset, $version, $preHealVersion, $live->checker, $dryRun, $expectedFingerprint);
    }

    private function terminalLiveResult(IntegrityResult $live, bool $dryRun): ?HealResult
    {
        if ($live->isRenderable()) {
            return new HealResult(HealOutcome::AlreadyRenderable, $live->checker, null, null, $dryRun);
        }
        if (!$live->isBroken()) {
            return new HealResult(HealOutcome::Unverifiable, $live->checker, null, $live->reason, $dryRun);
        }

        return null;
    }

    /** @param list<Version> $versions */
    private function firstRenderableVersion(
        IntegrityCheckerInterface $checker,
        array $versions,
        Asset $asset,
    ): ?Version {
        $extension = strtolower(pathinfo((string) $asset->getFilename(), PATHINFO_EXTENSION));
        foreach ($versions as $version) {
            $binary = $this->versionBinary($version);
            if ($binary !== null && $binary !== '' && $checker->checkBinary($binary, $extension)->isRenderable()) {
                return $version;
            }
        }

        return null;
    }

    private function healFromVersion(
        Asset $asset,
        Version $version,
        ?int $preHealVersion,
        string $checker,
        bool $dryRun,
        ?string $expectedFingerprint,
    ): HealResult {
        $assetId = (int) $asset->getId();
        $toVersion = (int) $version->getId();
        if ($dryRun) {
            return new HealResult(HealOutcome::Healed, $checker, $toVersion, null, true);
        }

        $previewResult = new HealResult(HealOutcome::Healed, $checker, $toVersion, null, true);
        $this->assertPlanUnchanged($assetId, $asset, $previewResult, $expectedFingerprint);
        $logId = $this->prepareHealRestore($asset, $preHealVersion, $toVersion, $checker);
        if ($logId instanceof HealResult) {
            return $logId;
        }

        $failed = $this->restoreVersionForHeal($asset, $version, $previewResult, $expectedFingerprint, $logId, $checker);
        if ($failed !== null) {
            return $failed;
        }

        $committed = $this->healLog->commitHeal($logId);

        return $this->finish(
            new HealResult(
                HealOutcome::Healed,
                $checker,
                $toVersion,
                $committed ? null : 'Asset healed, but its audit row could not be finalised; this heal may not be undoable.',
            ),
            $asset,
            $toVersion,
        );
    }

    private function prepareHealRestore(
        Asset $asset,
        ?int $preHealVersion,
        int $toVersion,
        string $checker,
    ): int|HealResult {
        $preHeal = new AssetHealEvent($asset, $toVersion);
        $this->eventDispatcher->dispatch($preHeal, AssetPilotEvents::INTEGRITY_PRE_HEAL);
        if ($preHeal->isCancelled()) {
            return $this->finish(new HealResult(HealOutcome::Skipped, $checker, $toVersion, 'Heal cancelled by a listener.'), $asset, $toVersion);
        }

        $logId = $this->healLog->beginHeal((int) $asset->getId(), $preHealVersion, $toVersion, $checker);
        if ($logId === null) {
            return $this->finish(new HealResult(HealOutcome::Skipped, $checker, $toVersion, 'Could not open the integrity heal audit row; restore not attempted.'), $asset, $toVersion);
        }

        return $logId;
    }


    private function restoreVersionForHeal(
        Asset $asset,
        Version $version,
        HealResult $previewResult,
        ?string $expectedFingerprint,
        int $logId,
        string $checker,
    ): ?HealResult {
        $assetId = (int) $asset->getId();
        $toVersion = (int) $version->getId();
        try {
            $restoreAsset = $asset;
            if ($expectedFingerprint !== null) {
                $restoreAsset = $this->loadAsset($assetId);
                $this->assertPlanUnchanged($assetId, $restoreAsset, $previewResult, $expectedFingerprint);
                if ($restoreAsset === null || $restoreAsset instanceof Asset\Folder) {
                    throw new StaleApplyPlanException(sprintf('Asset %d changed after the integrity preview.', $assetId));
                }
            }
            $this->restore($restoreAsset, $version);
        } catch (StaleApplyPlanException $e) {
            $this->healLog->failHeal($logId);

            throw $e;
        } catch (\Throwable $e) {
            $this->healLog->failHeal($logId);
            $this->logger->error('Asset Pilot: integrity heal restore failed for asset {id}: {error}', [
                'id' => $assetId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return $this->finish(new HealResult(HealOutcome::Skipped, $checker, $toVersion, 'Restore failed: ' . $e->getMessage()), $asset, $toVersion);
        }

        return null;
    }

    private function unrecoverableResult(
        Asset $asset,
        string $checker,
        bool $dryRun,
        ?string $expectedFingerprint,
    ): HealResult {
        $reason = 'No renderable version to roll back to.';
        if ($dryRun) {
            return new HealResult(HealOutcome::Unrecoverable, $checker, null, $reason, true);
        }

        $assetId = (int) $asset->getId();
        $previewResult = new HealResult(HealOutcome::Unrecoverable, $checker, null, $reason, true);
        $this->assertPlanUnchanged($assetId, $asset, $previewResult, $expectedFingerprint);
        $firstUnrecoverable = $this->healLog->latestStatus($assetId) !== IntegrityHealLog::STATUS_UNRECOVERABLE;
        $this->healLog->record($assetId, null, null, $checker, IntegrityHealLog::STATUS_UNRECOVERABLE);
        $this->routeUnrecoverable($asset, $firstUnrecoverable);

        return $this->finish(new HealResult(HealOutcome::Unrecoverable, $checker, null, $reason), $asset, null);
    }


    private function checkedResult(
        HealResult $result,
        int $assetId,
        ?Asset $asset,
        ?string $expectedFingerprint,
    ): HealResult {
        $this->assertPlanUnchanged($assetId, $asset, $this->asPreviewResult($result), $expectedFingerprint);

        return $result;
    }

    private function assertPlanUnchanged(
        int $assetId,
        ?Asset $asset,
        HealResult $previewResult,
        ?string $expectedFingerprint,
    ): void {
        if ($expectedFingerprint === null) {
            return;
        }
        if ($this->healFingerprints === null) {
            throw new StaleApplyPlanException('Integrity plan validation is unavailable. Preview the heal again.');
        }

        $this->healFingerprints->assertUnchanged($assetId, $asset, $previewResult, $expectedFingerprint);
    }

    private function asPreviewResult(HealResult $result): HealResult
    {
        return new HealResult(
            $result->outcome,
            $result->checker,
            $result->toVersion,
            $result->reason,
            true,
            $result->observerWarnings,
        );
    }

    /**
     * Dispatch INTEGRITY_POST_HEAL with the terminal outcome (every result that got past PRE_HEAL is
     * reported, not only successes, so monitoring listeners can observe failures and vetoes) and
     * return the result unchanged. $targetVersion is null when no version was rolled back to.
     */
    private function finish(HealResult $result, Asset $asset, ?int $targetVersion): HealResult
    {
        $errors = NonFatalEventDispatcher::dispatch(
            $this->eventDispatcher,
            new AssetHealEvent($asset, $targetVersion, $result->outcome),
            AssetPilotEvents::INTEGRITY_POST_HEAL,
            $this->logger,
            ['asset_id' => $asset->getId()],
        );

        if ($errors !== []) {
            return new HealResult(
                $result->outcome,
                $result->checker,
                $result->toVersion,
                $result->reason,
                $result->dryRun,
                [...$result->observerWarnings, 'Integrity post-heal observer delivery failed.'],
            );
        }

        return $result;
    }

    /**
     * Reverse the most recent heal of an asset: restore the pre-heal version. Returns false when
     * there is nothing undoable (no heal, or its pre-heal version is gone).
     */
    public function undo(int $assetId): bool
    {
        return $this->undoDetailed($assetId)->isSuccessful();
    }

    public function undoDetailed(int $assetId, bool $dryRun = false): UndoHealResult
    {
        if (!$this->loopGuard->acquireAsset($assetId)) {
            return new UndoHealResult(UndoHealOutcome::Skipped, 'Asset is being processed by another job.', $dryRun, UndoHealReason::AssetBusy);
        }

        try {
            return $this->undoLocked($assetId, $dryRun);
        } finally {
            $this->loopGuard->releaseAsset($assetId);
        }
    }

    private function undoLocked(int $assetId, bool $dryRun): UndoHealResult
    {
        $asset = $this->loadAsset($assetId);
        $preflight = $this->undoPreflightResult($asset, $dryRun);
        if ($preflight !== null) {
            return $preflight;
        }

        assert($asset instanceof Asset && !($asset instanceof Asset\Folder));
        $entry = $this->healLog->findUndoable($assetId);
        if ($entry === null || $entry['from_version'] === null) {
            return new UndoHealResult(UndoHealOutcome::Skipped, 'No reversible heal is recorded for this asset.', $dryRun, UndoHealReason::NoReversibleHeal);
        }

        $version = $this->loadVersion($entry['from_version']);
        if ($version === null) {
            return new UndoHealResult(UndoHealOutcome::Skipped, 'The pre-heal version is no longer available.', $dryRun, UndoHealReason::VersionMissing);
        }
        if (!$this->stillInHealedState($asset, $entry['to_version'])) {
            $this->logger->warning('Asset Pilot: refused to undo heal of asset {id}: its binary changed since the heal, so rolling back to the pre-heal version would overwrite newer content.', [
                'id' => $assetId,
            ]);

            return new UndoHealResult(UndoHealOutcome::Skipped, 'The asset changed after it was healed.', $dryRun, UndoHealReason::AssetChanged);
        }
        if ($dryRun) {
            return new UndoHealResult(UndoHealOutcome::WouldReverse, dryRun: true);
        }

        return $this->restoreUndo($assetId, $asset, $version, $entry['id']);
    }

    private function undoPreflightResult(?Asset $asset, bool $dryRun): ?UndoHealResult
    {
        if ($asset === null || $asset instanceof Asset\Folder) {
            return new UndoHealResult(UndoHealOutcome::Skipped, 'Asset not found.', $dryRun, UndoHealReason::AssetNotFound);
        }
        if ($dryRun && !$this->authorization->isAllowed($asset, 'view')) {
            return new UndoHealResult(UndoHealOutcome::Skipped, 'Not permitted to inspect this asset.', true, UndoHealReason::NotPermitted);
        }
        if (!$this->authorization->isAllowed($asset, 'publish')) {
            return new UndoHealResult(UndoHealOutcome::Skipped, 'Not permitted to undo this heal.', $dryRun, UndoHealReason::NotPermitted);
        }
        if (AssetProtection::isLocked($asset, $this->lockProperty)) {
            return new UndoHealResult(UndoHealOutcome::Skipped, 'Asset is locked.', $dryRun, UndoHealReason::AssetLocked);
        }
        if ($this->matchingExcludeFolder($asset->getRealFullPath()) !== null) {
            return new UndoHealResult(UndoHealOutcome::Skipped, 'Asset is in an excluded folder.', $dryRun, UndoHealReason::ExcludedFolder);
        }

        return null;
    }

    private function restoreUndo(int $assetId, Asset $asset, Version $version, int $logId): UndoHealResult
    {
        try {
            $this->loopGuard->refreshAsset($assetId);
            $this->restore($asset, $version);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to undo heal for asset {id}', [
                'id' => $assetId,
                'exception' => $e,
            ]);

            return new UndoHealResult(UndoHealOutcome::Failed, 'Failed to restore the pre-heal version.', reasonCode: UndoHealReason::RestoreFailed);
        }

        if (!$this->healLog->markUndone($logId)) {
            return new UndoHealResult(UndoHealOutcome::Failed, 'The asset was restored, but the integrity log could not be marked undone.', reasonCode: UndoHealReason::LogUpdateFailed);
        }

        return new UndoHealResult(UndoHealOutcome::Reversed);
    }


    /**
     * True when the asset's live binary still equals the version the heal restored it to. A null or
     * unloadable to_version (or an unreadable binary) cannot be confirmed and is treated as a
     * mismatch, so undo errs on the side of not overwriting.
     */
    protected function stillInHealedState(Asset $asset, ?int $toVersion): bool
    {
        if ($toVersion === null) {
            return false;
        }

        $version = $this->loadVersion($toVersion);
        if ($version === null) {
            return false;
        }

        $healed = $this->versionBinary($version);
        $live = $this->liveBinary($asset);

        return $healed !== null && $live !== null && hash_equals($healed, $live);
    }

    /** The asset's current on-disk bytes, or null if unreadable. A seam so undo is unit-testable. */
    protected function liveBinary(Asset $asset): ?string
    {
        $stream = $asset->getStream();
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
            $this->loopGuard->refreshAsset($assetId);
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
        return Asset::getById($assetId, ['force' => true]);
    }

    protected function reloadAsset(Asset $asset): ?Asset
    {
        return $this->loadAsset((int) $asset->getId());
    }

    protected function loadVersion(int $versionId): ?Version
    {
        return Version::getById($versionId);
    }

    private function matchingExcludeFolder(string $path): ?string
    {
        foreach ($this->excludeFolders as $excludedFolder) {
            if (str_starts_with($path, rtrim((string) $excludedFolder, '/') . '/')) {
                return (string) $excludedFolder;
            }
        }

        return null;
    }
}
