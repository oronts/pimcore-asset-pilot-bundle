<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Merge\Strategy;

use Oronts\AssetPilotBundle\Enum\DependencyUsageVerdict;
use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Merge\CopyDisposition;
use Oronts\AssetPilotBundle\Merge\DuplicateMergeContextInterface;
use Oronts\AssetPilotBundle\Merge\RepointReport;
use Oronts\AssetPilotBundle\Merge\ResumableDuplicateMergeStrategyInterface;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Service\AssetDeletionFenceInterface;
use Oronts\AssetPilotBundle\Service\AssetProtection;
use Oronts\AssetPilotBundle\Service\ContentUsageScannerInterface;
use Oronts\AssetPilotBundle\Service\DependencyUsageVerifierInterface;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;

/**
 * Frees storage immediately: once a copy's references are fully repointed, delete the copy (recoverable
 * only via Pimcore's version history, not the quarantine restore). Before the destructive delete it
 * RE-VERIFIES the copy has NO remaining reverse dependency of any type (object, document or asset), so
 * a reference that reappeared after the repoint leaves the copy in place rather than orphaning it.
 */
class RepointAndDeleteStrategy implements ResumableDuplicateMergeStrategyInterface
{
    public function __construct(
        protected readonly LoggerInterface $logger,
        protected readonly ElementAuthorizationInterface $authorization,
        protected readonly DependencyUsageVerifierInterface $dependencyVerifier,
        protected readonly ContentUsageScannerInterface $contentScanner,
        protected readonly AssetDeletionFenceInterface $deletionFence,
        protected readonly string $lockProperty = AssetProtection::DEFAULT_LOCK_PROPERTY,
    ) {}

    public function name(): string
    {
        return 'delete';
    }

    public function repointsReferences(): bool
    {
        return true;
    }

    public function disposeCopy(RepointReport $report, DuplicateMergeContextInterface $context): CopyDisposition
    {
        $copyId = $context->copyId();
        if (!$report->fullyRepointed) {
            return new CopyDisposition($copyId, DispositionOutcome::LeftReferenced, sprintf(
                '%d reference(s) could not be repointed: %s',
                count($report->blocked),
                implode('; ', $report->blocked),
            ));
        }

        $fenceToken = $this->deletionFence->acquire($copyId, 'duplicate_delete');
        if ($fenceToken === null) {
            return new CopyDisposition($copyId, DispositionOutcome::LeftReferenced, 'the copy is being deleted by another operation');
        }

        try {
            $referenceBlock = $this->referenceBlockReason($copyId);
            if ($referenceBlock !== null) {
                return new CopyDisposition($copyId, DispositionOutcome::LeftReferenced, $referenceBlock);
            }

            if ($this->isProtected($copyId)) {
                return new CopyDisposition($copyId, DispositionOutcome::LeftError, 'the asset is protected from automated changes');
            }

            // Workspace ACL: a flat operate/admin permission is not enough to hard-delete an element the
            // acting user has no delete right on (the quarantine strategy applies the same gate). isAllowed()
            // resolves the current user and returns true on CLI.
            if (!$this->isDeletionAllowed($copyId)) {
                return new CopyDisposition($copyId, DispositionOutcome::LeftError, 'not permitted to delete this asset');
            }

            $this->deletionFence->refreshOrFail($copyId, $fenceToken);
            if ($this->deleteAsset($copyId)) {
                return new CopyDisposition($copyId, DispositionOutcome::Deleted);
            }

            return new CopyDisposition($copyId, DispositionOutcome::LeftError, 'the copy could not be deleted');
        } finally {
            // Best-effort: the delete already succeeded, so a throwing fence release must not turn a
            // completed deletion into a reported failure; a leaked fence row is reaped by maintenance.
            try {
                $this->deletionFence->release($copyId, $fenceToken);
            } catch (\Throwable $e) {
                $this->logger->error('Asset Pilot: failed to release deletion fence for copy {id}', ['id' => $copyId, 'exception' => $e]);
            }
        }
    }

    public function recoverDisposition(int $copyId, RepointReport $report): ?CopyDisposition
    {
        return $this->loadAsset($copyId) === null
            ? new CopyDisposition($copyId, DispositionOutcome::Deleted)
            : null;
    }

    /** Whether the copy still has any reverse dependency (of any element type) right now. */
    protected function hasReferences(int $assetId): bool
    {
        $asset = $this->loadAsset($assetId);

        return $asset !== null && $asset->getDependencies()->getRequiredBy(0, 1) !== [];
    }

    protected function referenceBlockReason(int $assetId): ?string
    {
        $asset = $this->loadAsset($assetId);
        if (!$asset instanceof Asset || $asset instanceof Asset\Folder) {
            return 'the copy no longer exists';
        }
        if (!$this->contentScanner->canVerify()) {
            return 'hard-coded content reference verification is not configured; not deleting';
        }
        if ($this->hasReferences($assetId) || $this->dependencyVerifier->verdict($asset) !== DependencyUsageVerdict::Safe) {
            return 'a live dependency exists after the repoint; not deleting';
        }
        if ($this->contentScanner->freshlyReferencedInContent($asset)) {
            return 'a hard-coded content reference exists after the repoint; not deleting';
        }

        return null;
    }

    /** Whether the acting user holds the Pimcore workspace delete right on the copy. */
    protected function isDeletionAllowed(int $assetId): bool
    {
        $asset = $this->loadAsset($assetId);

        return $asset !== null && $this->authorization->isAllowed($asset, 'delete');
    }

    protected function isProtected(int $assetId): bool
    {
        $asset = $this->loadAsset($assetId);

        return $asset !== null && AssetProtection::isLocked($asset, $this->lockProperty);
    }

    protected function deleteAsset(int $assetId): bool
    {
        $asset = $this->loadAsset($assetId);
        if ($asset === null) {
            return false;
        }

        try {
            $asset->delete();

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to delete duplicate copy {id}: {error}', [
                'id' => $assetId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return false;
        }
    }

    protected function loadAsset(int $assetId): ?Asset
    {
        return Asset::getById($assetId, ['force' => true]);
    }
}
