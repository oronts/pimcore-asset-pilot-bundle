<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\DriftEligibility;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Event\AssetMoveEvent;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Model\DriftAssessment;
use Oronts\AssetPilotBundle\Model\MovePlan;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Naming\NamingStrategyInterface;
use Oronts\AssetPilotBundle\Strategy\SideEffectFreeConflictStrategyInterface;
use Oronts\AssetPilotBundle\Strategy\StrategyResolver;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Evaluates the move gates (strategy, naming, already-at-target, cancellable PRE_MOVE, lock,
 * excluded folder) and returns a single MovePlan. AssetOrganizer's dry-run and live execution
 * both call this so the preview's skip reasons always match what the move would actually do.
 */
class MovePlanner
{
    /** @param string[] $excludeFolders */
    public function __construct(
        protected readonly StrategyResolver $strategyResolver,
        protected readonly NamingStrategyInterface $namingStrategy,
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly array $excludeFolders = [],
        protected readonly string $lockProperty = AssetProtection::DEFAULT_LOCK_PROPERTY,
    ) {}

    public function plan(
        Asset $asset,
        AbstractObject $object,
        Rule $rule,
        string $resolvedPath,
        TriggerType $triggerType,
        bool $dryRun,
    ): MovePlan {
        $sourcePath = $asset->getRealFullPath();

        $strategy = $this->strategyResolver->resolve($rule);
        if (!$strategy->resolve($asset, $object, $rule)) {
            return MovePlan::skip($resolvedPath, 'Strategy rejected move');
        }

        [$targetFilename, $fullTargetPath] = $this->resolveTarget($asset, $resolvedPath);

        if ($sourcePath === $fullTargetPath) {
            return MovePlan::skip($fullTargetPath, 'Asset already at target path');
        }

        $preMoveEvent = new AssetMoveEvent($asset, $sourcePath, $fullTargetPath, $object, $rule, $triggerType, dryRun: $dryRun);
        $this->eventDispatcher->dispatch($preMoveEvent, AssetPilotEvents::PRE_MOVE);
        if ($preMoveEvent->isCancelled()) {
            return MovePlan::skip($fullTargetPath, 'Cancelled by event listener');
        }

        if (AssetProtection::isLocked($asset, $this->lockProperty)) {
            return MovePlan::skip($fullTargetPath, 'Asset is locked');
        }

        $excludedFolder = $this->matchingExcludeFolder($sourcePath);
        if ($excludedFolder !== null) {
            return MovePlan::skip($fullTargetPath, 'Asset is in excluded folder: ' . $excludedFolder);
        }

        return MovePlan::proceed($resolvedPath, $targetFilename, $fullTargetPath);
    }

    public function assessDrift(Asset $asset, AbstractObject $object, Rule $rule, string $resolvedPath): DriftAssessment
    {
        [, $targetPath] = $this->resolveTarget($asset, $resolvedPath);
        $sourcePath = $asset->getRealFullPath();

        if ($sourcePath === $targetPath) {
            return new DriftAssessment($targetPath, DriftEligibility::NoKnownBlock);
        }
        if (AssetProtection::isLocked($asset, $this->lockProperty)) {
            return new DriftAssessment($targetPath, DriftEligibility::Blocked, 'Asset is locked');
        }
        $excludedFolder = $this->matchingExcludeFolder($sourcePath);
        if ($excludedFolder !== null) {
            return new DriftAssessment($targetPath, DriftEligibility::Blocked, 'Asset is in excluded folder: ' . $excludedFolder);
        }

        $strategy = $this->strategyResolver->resolve($rule);
        if (!$strategy instanceof SideEffectFreeConflictStrategyInterface) {
            return new DriftAssessment($targetPath, DriftEligibility::RuntimeCheckRequired, 'The configured strategy is evaluated only when a move is requested');
        }
        if (!$strategy->resolve($asset, $object, $rule)) {
            return new DriftAssessment($targetPath, DriftEligibility::Blocked, 'Strategy rejected move');
        }

        return new DriftAssessment($targetPath, DriftEligibility::NoKnownBlock);
    }

    /** @return array{string, string} */
    private function resolveTarget(Asset $asset, string $resolvedPath): array
    {
        $targetFilename = $this->namingStrategy->generateName($asset, $resolvedPath);

        return [$targetFilename, rtrim($resolvedPath, '/') . '/' . $targetFilename];
    }

    protected function matchingExcludeFolder(string $path): ?string
    {
        foreach ($this->excludeFolders as $excludedFolder) {
            if (str_starts_with($path, rtrim($excludedFolder, '/') . '/')) {
                return $excludedFolder;
            }
        }

        return null;
    }
}
