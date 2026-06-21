<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Merge\Strategy;

use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Merge\CopyDisposition;
use Oronts\AssetPilotBundle\Merge\DuplicateMergeStrategyInterface;
use Oronts\AssetPilotBundle\Merge\RepointReport;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;

/**
 * Frees storage immediately: once a copy's references are fully repointed, delete the copy (recoverable
 * only via Pimcore's version history, not the quarantine restore). Before the destructive delete it
 * RE-VERIFIES the copy has NO remaining reverse dependency of any type (object, document or asset), so
 * a reference that reappeared after the repoint leaves the copy in place rather than orphaning it.
 */
class RepointAndDeleteStrategy implements DuplicateMergeStrategyInterface
{
    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}

    public function name(): string
    {
        return 'delete';
    }

    public function repointsReferences(): bool
    {
        return true;
    }

    public function disposeCopy(int $copyId, RepointReport $report): CopyDisposition
    {
        if (!$report->fullyRepointed) {
            return new CopyDisposition($copyId, DispositionOutcome::LeftReferenced, sprintf(
                '%d reference(s) could not be repointed: %s',
                count($report->blocked),
                implode('; ', $report->blocked),
            ));
        }

        if ($this->hasReferences($copyId)) {
            return new CopyDisposition($copyId, DispositionOutcome::LeftReferenced, 'a reference reappeared after the repoint; not deleting');
        }

        // Workspace ACL: a flat operate/admin permission is not enough to hard-delete an element the
        // acting user has no delete right on (the quarantine strategy applies the same gate). isAllowed()
        // resolves the current user and returns true on CLI.
        if (!$this->isDeletionAllowed($copyId)) {
            return new CopyDisposition($copyId, DispositionOutcome::LeftError, 'not permitted to delete this asset');
        }

        if ($this->deleteAsset($copyId)) {
            return new CopyDisposition($copyId, DispositionOutcome::Deleted);
        }

        return new CopyDisposition($copyId, DispositionOutcome::LeftError, 'the copy could not be deleted');
    }

    /** Whether the copy still has any reverse dependency (of any element type) right now. */
    protected function hasReferences(int $assetId): bool
    {
        $asset = Asset::getById($assetId);

        return $asset !== null && $asset->getDependencies()->getRequiredBy(0, 1) !== [];
    }

    /** Whether the acting user holds the Pimcore workspace delete right on the copy. */
    protected function isDeletionAllowed(int $assetId): bool
    {
        $asset = Asset::getById($assetId);

        return $asset !== null && $asset->isAllowed('delete');
    }

    protected function deleteAsset(int $assetId): bool
    {
        $asset = Asset::getById($assetId);
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
}
