<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Merge\Strategy;

use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Merge\CopyDisposition;
use Oronts\AssetPilotBundle\Merge\DuplicateMergeStrategyInterface;
use Oronts\AssetPilotBundle\Merge\RepointReport;
use Oronts\AssetPilotBundle\Service\AssetDependencyResolver;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;

/**
 * Frees storage immediately: once a copy's references are fully repointed, delete the copy (recoverable
 * only via Pimcore's version history, not the quarantine restore). Before the destructive delete it
 * RE-VERIFIES the copy is unreferenced, so a reference that reappeared after the repoint leaves the
 * copy in place rather than orphaning it.
 */
class RepointAndDeleteStrategy implements DuplicateMergeStrategyInterface
{
    public function __construct(
        protected readonly AssetDependencyResolver $dependencies,
        protected readonly LoggerInterface $logger,
    ) {}

    public function name(): string
    {
        return 'delete';
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

        if ($this->dependencies->dependentObjectIds($copyId, 1) !== []) {
            return new CopyDisposition($copyId, DispositionOutcome::LeftReferenced, 'a reference reappeared after the repoint; not deleting');
        }

        if ($this->deleteAsset($copyId)) {
            return new CopyDisposition($copyId, DispositionOutcome::Deleted);
        }

        return new CopyDisposition($copyId, DispositionOutcome::LeftError, 'the copy could not be deleted');
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
