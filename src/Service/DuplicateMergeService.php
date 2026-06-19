<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Merge\CopyDisposition;
use Oronts\AssetPilotBundle\Merge\DuplicateMergeStrategyInterface;
use Oronts\AssetPilotBundle\Merge\MergeOutcome;
use Oronts\AssetPilotBundle\Model\DuplicateGroup;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates a duplicate merge: choose the canonical asset, repoint every other copy's references
 * onto it, then hand each copy to the configured disposition strategy. Detection (which assets share a
 * content hash) is owned by DuplicateDetectionService; this service owns the destructive
 * consolidation. A dry run repoints nothing and disposes nothing — it only reports what would happen.
 */
class DuplicateMergeService
{
    /** @var array<string, DuplicateMergeStrategyInterface> */
    private array $strategies = [];

    /**
     * @param iterable<DuplicateMergeStrategyInterface> $strategies
     */
    public function __construct(
        iterable $strategies,
        protected readonly DuplicateReferenceRepointer $repointer,
        protected readonly LoggerInterface $logger,
        protected readonly string $defaultStrategy = 'quarantine',
    ) {
        foreach ($strategies as $strategy) {
            $this->strategies[$strategy->name()] = $strategy;
        }
    }

    /**
     * @return list<string> the name of every registered strategy (for the API/command to advertise)
     */
    public function availableStrategies(): array
    {
        return array_keys($this->strategies);
    }

    public function merge(DuplicateGroup $group, ?int $canonicalId = null, ?string $strategyName = null, bool $dryRun = false): MergeOutcome
    {
        $strategy = $this->resolveStrategy($strategyName ?? $this->defaultStrategy);

        if (count($group->assetIds) < 2) {
            return new MergeOutcome($group->checksum, 0, []);
        }

        $canonical = $this->pickCanonical($group, $canonicalId);
        $copies = array_values(array_filter($group->assetIds, static fn (int $id): bool => $id !== $canonical));

        $dispositions = [];
        foreach ($copies as $copyId) {
            $report = $this->repointer->repoint($copyId, $canonical, $dryRun);

            if ($dryRun) {
                // A dry run cannot recompute dependencies (nothing is saved), so it never claims the
                // copy is disposable: it only reports what would be rewritten and what plainly cannot.
                $reason = sprintf(
                    'dry run: %d object reference(s) would be repointed%s; nested/advanced references are verified only on --apply',
                    $report->repointedObjects,
                    $report->blocked === [] ? '' : sprintf('; %d reference(s) cannot be rewritten (%s)', count($report->blocked), implode('; ', $report->blocked)),
                );
                $dispositions[] = new CopyDisposition($copyId, DispositionOutcome::Skipped, $reason);
                continue;
            }

            $dispositions[] = $strategy->disposeCopy($copyId, $report);
        }

        return new MergeOutcome($group->checksum, $canonical, $dispositions);
    }

    private function resolveStrategy(string $name): DuplicateMergeStrategyInterface
    {
        return $this->strategies[$name]
            ?? throw new \InvalidArgumentException(sprintf(
                'Unknown duplicate-merge strategy "%s". Available: %s.',
                $name,
                implode(', ', $this->availableStrategies()),
            ));
    }

    private function pickCanonical(DuplicateGroup $group, ?int $canonicalId): int
    {
        if ($canonicalId === null) {
            return min($group->assetIds);
        }

        if (!in_array($canonicalId, $group->assetIds, true)) {
            throw new \InvalidArgumentException(sprintf('Canonical asset %d is not a member of this duplicate group.', $canonicalId));
        }

        return $canonicalId;
    }
}
