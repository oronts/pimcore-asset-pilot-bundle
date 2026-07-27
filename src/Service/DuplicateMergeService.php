<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Enum\DuplicateMergePhase;
use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunKind;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Event\DuplicateMergeEvent;
use Oronts\AssetPilotBundle\Event\NonFatalEventDispatcher;
use Oronts\AssetPilotBundle\Exception\MergeLeaseLostException;
use Oronts\AssetPilotBundle\Exception\NotPermittedException;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Merge\CopyDisposition;
use Oronts\AssetPilotBundle\Merge\DuplicateMergeContext;
use Oronts\AssetPilotBundle\Merge\DuplicateMergeContextInterface;
use Oronts\AssetPilotBundle\Merge\DuplicateMergeStrategyInterface;
use Oronts\AssetPilotBundle\Merge\MergeOutcome;
use Oronts\AssetPilotBundle\Merge\ReferrerSnapshot;
use Oronts\AssetPilotBundle\Merge\RepointPreflight;
use Oronts\AssetPilotBundle\Merge\RepointReport;
use Oronts\AssetPilotBundle\Merge\ResumableDuplicateMergeStrategyInterface;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Model\DuplicateGroup;
use Oronts\AssetPilotBundle\Security\ActorContextStore;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Support\UniqueServiceMap;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class DuplicateMergeService implements DuplicateMergeServiceInterface
{
    public const OperationRunKind RUN_KIND = OperationRunKind::DuplicateMerge;

    private const string ITEM_TYPE = 'duplicate_copy';

    /** @var array<string, DuplicateMergeStrategyInterface> */
    private array $strategies;

    /** @param iterable<DuplicateMergeStrategyInterface> $strategies */
    public function __construct(
        iterable $strategies,
        protected readonly DuplicateReferenceRepointerInterface $repointer,
        protected readonly LoggerInterface $logger,
        protected readonly Connection $connection,
        protected readonly ElementAuthorizationInterface $authorization,
        protected readonly ActorContextStore $actors,
        protected readonly LoopGuard $loopGuard,
        protected readonly OperationRunStoreInterface $runs,
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly string $defaultStrategy = 'quarantine',
        protected readonly string $lockProperty = AssetProtection::DEFAULT_LOCK_PROPERTY,
    ) {
        $this->strategies = UniqueServiceMap::from(
            $strategies,
            static fn (DuplicateMergeStrategyInterface $strategy): string => $strategy->name(),
            'duplicate merge strategy',
        );
    }

    /** @return list<string> */
    public function availableStrategies(): array
    {
        return array_keys($this->strategies);
    }

    public function defaultStrategyName(): string
    {
        return $this->defaultStrategy;
    }

    public function preview(
        DuplicateGroup $group,
        ?int $canonicalId = null,
        ?string $strategyName = null,
    ): MergeOutcome {
        $strategy = $this->resolveStrategy($strategyName ?? $this->defaultStrategy);
        if (count($group->assetIds) < 2) {
            return new MergeOutcome($group->checksum, 0, []);
        }

        $canonicalId = $this->pickCanonical($group, $canonicalId);
        $copyIds = array_values(array_filter(
            $group->assetIds,
            static fn (int $assetId): bool => $assetId !== $canonicalId,
        ));
        $this->assertGroupAuthorized($group->assetIds, 'view');

        return $this->previewCopies($group, $canonicalId, $copyIds, $strategy);
    }

    /** @param array<string, string> $expectedFingerprints */
    public function merge(
        DuplicateGroup $group,
        array $expectedFingerprints,
        ?int $canonicalId = null,
        ?string $strategyName = null,
    ): MergeOutcome {
        $strategy = $this->resolveStrategy($strategyName ?? $this->defaultStrategy);
        if (count($group->assetIds) < 2) {
            return new MergeOutcome($group->checksum, 0, []);
        }

        $canonicalId = $this->pickCanonical($group, $canonicalId);
        $copyIds = array_values(array_filter(
            $group->assetIds,
            static fn (int $assetId): bool => $assetId !== $canonicalId,
        ));
        $this->assertGroupAuthorized($group->assetIds, 'publish');
        if ($this->fingerprintMap($group, $canonicalId) !== $expectedFingerprints) {
            throw new StaleApplyPlanException('The duplicate group changed after preview. Preview it again before applying.');
        }
        $preflights = $this->preflightCopies($copyIds, $canonicalId, 'publish');
        $runId = $this->createRun($group, $canonicalId, $strategy, $preflights, $expectedFingerprints);

        return $this->executeRun($runId);
    }

    public function resume(string $runId): MergeOutcome
    {
        $run = $this->runs->get($runId, $this->authorization->currentActor());
        if ($run === null || ($run['kind'] ?? null) !== self::RUN_KIND->value) {
            throw new \InvalidArgumentException('Duplicate merge run not found.');
        }

        $actor = OperationRunActor::fromRun($run);

        return $this->actors->runAs(
            $actor,
            fn (): MergeOutcome => OperationRunStatus::from((string) $run['status'])->isTerminal()
                ? $this->outcomeFromRun($run)
                : $this->executeRun($runId),
        );
    }

    /** @return list<ApplyPlanTarget> */
    public function planTargets(DuplicateGroup $group, int $canonicalId): array
    {
        $this->assertGroupAuthorized($group->assetIds, 'view');
        $fingerprints = $this->fingerprintMap($group, $canonicalId);

        return array_map(
            static fn (string $id, string $fingerprint): ApplyPlanTarget => new ApplyPlanTarget($id, $fingerprint),
            array_keys($fingerprints),
            array_values($fingerprints),
        );
    }

    /** @return array<string, string> */
    public function fingerprintMap(DuplicateGroup $group, int $canonicalId): array
    {
        $canonicalId = $this->pickCanonical($group, $canonicalId);
        $fingerprints = [];
        foreach ($group->assetIds as $assetId) {
            $asset = $this->loadAsset($assetId);
            if ($asset === null) {
                $fingerprints['asset:' . $assetId] = hash('sha256', 'missing');
                continue;
            }
            $dependencies = $this->connection->fetchAllAssociative(
                'SELECT sourcetype, sourceid FROM dependencies WHERE targettype = ? AND targetid = ? ORDER BY sourcetype ASC, sourceid ASC',
                ['asset', $assetId],
            );
            $referrers = [];
            $blocked = [];
            if ($assetId !== $canonicalId) {
                $preflight = $this->repointer->preflight($assetId, $canonicalId, 'view');
                $referrers = array_map(
                    static fn (ReferrerSnapshot $snapshot): array => $snapshot->toArray(),
                    array_values($preflight->referrersByKey()),
                );
                $blocked = $preflight->blocked;
                sort($blocked, SORT_STRING);
            }
            $fingerprints['asset:' . $assetId] = $this->hash([
                'checksum' => $asset->getChecksum(),
                'dependencies' => $dependencies,
                'locked' => $this->assetIsProtected($assetId),
                'modifiedAt' => $asset->getModificationDate(),
                'path' => $asset->getRealFullPath(),
                'referrers' => $referrers,
                'blocked' => $blocked,
            ]);
        }
        ksort($fingerprints, SORT_STRING);

        return $fingerprints;
    }

    /**
     * @param list<int> $copyIds
     */
    private function previewCopies(
        DuplicateGroup $group,
        int $canonicalId,
        array $copyIds,
        DuplicateMergeStrategyInterface $strategy,
    ): MergeOutcome {
        $dispositions = [];
        foreach ($copyIds as $copyId) {
            $report = $strategy->repointsReferences()
                ? $this->repointer->repoint($copyId, $canonicalId, true)
                : new RepointReport($copyId, $canonicalId, 0, []);

            $reason = $strategy->repointsReferences()
                ? sprintf(
                    'dry run: %d object reference(s) would be repointed%s; nested/advanced references are verified only on apply',
                    $report->repointedObjects,
                    $report->blocked === [] ? '' : sprintf('; %d reference(s) cannot be rewritten (%s)', count($report->blocked), implode('; ', $report->blocked)),
                )
                : 'dry run: references are left intact; the copy would be quarantined only if it is already unreferenced';
            $dispositions[] = new CopyDisposition($copyId, DispositionOutcome::Skipped, $reason);
        }

        return new MergeOutcome($group->checksum, $canonicalId, $dispositions);
    }

    /** @param list<int> $copyIds @return array<int, RepointPreflight> */
    private function preflightCopies(array $copyIds, int $canonicalId, string $permission): array
    {
        $preflights = [];
        foreach ($copyIds as $copyId) {
            $preflights[$copyId] = $this->repointer->preflight($copyId, $canonicalId, $permission);
        }

        return $preflights;
    }

    /** @param array<int, RepointPreflight> $preflights @param array<string, string> $reviewedFingerprints */
    private function createRun(
        DuplicateGroup $group,
        int $canonicalId,
        DuplicateMergeStrategyInterface $strategy,
        array $preflights,
        array $reviewedFingerprints,
    ): string {
        $canonicalFingerprint = $this->assetIdentityFingerprint($canonicalId);
        $items = [];
        foreach ($preflights as $copyId => $preflight) {
            $items[] = [
                'key' => $this->itemKey($copyId),
                'type' => self::ITEM_TYPE,
                'id' => $copyId,
                'fingerprint' => $this->assetIdentityFingerprint($copyId),
                'payload' => [
                    'blocked' => $preflight->blocked,
                    'canonicalFingerprint' => $canonicalFingerprint,
                    'referrers' => $preflight->serializedReferrers(),
                ],
                'state' => [
                    'phase' => DuplicateMergePhase::Prepared->value,
                    'repointReport' => null,
                ],
            ];
        }

        return $this->runs->create(self::RUN_KIND, $this->authorization->currentActor(), $items, [
            'assetIds' => array_values($group->assetIds),
            'canonicalId' => $canonicalId,
            'checksum' => $group->checksum,
            'strategy' => $strategy->name(),
            'reviewedFingerprints' => $reviewedFingerprints,
        ]);
    }

    private function executeRun(string $runId): MergeOutcome
    {
        $actor = $this->authorization->currentActor();
        $run = $this->runs->get($runId, $actor);
        if ($run === null || ($run['kind'] ?? null) !== self::RUN_KIND->value) {
            throw new \InvalidArgumentException('Duplicate merge run not found.');
        }

        $request = $this->requestFromRun($run);
        $strategy = $this->resolveStrategy($request['strategy']);
        $openItems = array_values(array_filter(
            $run['items'],
            static fn (array $item): bool => in_array(
                $item['status'],
                [OperationRunItemStatus::Queued->value, OperationRunItemStatus::Running->value],
                true,
            ),
        ));
        if ($openItems === []) {
            $this->runs->finish($runId);

            return $this->outcomeFromRequiredRun($runId);
        }

        $currentPreflights = [];
        foreach ($openItems as $item) {
            $copyId = (int) $item['target_id'];
            $currentPreflights[$copyId] = $this->repointer->preflight($copyId, $request['canonicalId'], 'publish');
        }

        $locks = $this->acquireLocks($request['assetIds'], $openItems, $currentPreflights);
        if ($locks === null) {
            return $this->outcomeFromRequiredRun($runId);
        }

        try {
            $this->assertInitialReviewedState($run, $request);
            if (!$this->runs->resume($runId)) {
                if ($this->runs->isCancellationRequested($runId)) {
                    $this->cancelAvailableItems($runId, $openItems);
                    $this->runs->finish($runId);
                }

                return $this->outcomeFromRequiredRun($runId);
            }

            foreach ($openItems as $item) {
                if ($this->runs->isCancellationRequested($runId)) {
                    break;
                }

                $itemKey = (string) $item['item_key'];
                if (!$this->loopGuard->acquireOperationRunItem($runId, $itemKey)) {
                    continue;
                }
                $token = $this->loopGuard->beginOperationRunItemLease($runId, $itemKey);
                try {
                    $this->refreshLocks($locks);
                    $this->loopGuard->refreshOperationRunItem($runId, $itemKey);
                    if (!$this->runs->resumeItem($runId, $itemKey, $token)) {
                        continue;
                    }

                    $this->processOpenItem($runId, $item, $request, $strategy, $this->mergeContext($runId, $item, $request, $locks));
                } finally {
                    $this->loopGuard->releaseOperationRunItem($runId, $itemKey);
                }
            }
        } finally {
            $this->releaseLocks($locks);
        }

        $this->runs->finish($runId);

        return $this->outcomeFromRequiredRun($runId);
    }

    /**
     * @param array<string, mixed> $run
     * @param array{assetIds: list<int>, canonicalId: int, checksum: string, strategy: string, reviewedFingerprints: array<string, string>} $request
     */
    private function assertInitialReviewedState(array $run, array $request): void
    {
        if (($run['retry_of'] ?? null) !== null || array_any(
            $run['items'],
            fn (array $item): bool => ($item['status'] ?? null) !== OperationRunItemStatus::Queued->value
                || $this->resumePhase($item) !== DuplicateMergePhase::Prepared,
        )) {
            return;
        }

        $group = new DuplicateGroup(
            $request['checksum'],
            0,
            count($request['assetIds']),
            $request['assetIds'],
        );
        if ($this->fingerprintMap($group, $request['canonicalId']) !== $request['reviewedFingerprints']) {
            throw new StaleApplyPlanException('The duplicate group changed after preview. Preview it again before applying.');
        }
    }

    /**
     * @param array<string, mixed> $item
     * @param array{assetIds: list<int>, canonicalId: int, checksum: string, strategy: string, reviewedFingerprints: array<string, string>} $request
     */
    private function processOpenItem(
        string $runId,
        array $item,
        array $request,
        DuplicateMergeStrategyInterface $strategy,
        DuplicateMergeContextInterface $context,
    ): void {
        try {
            $this->processItem($runId, $item, $request, $strategy, $context);
        } catch (NotPermittedException|StaleApplyPlanException $e) {
            $this->completeItem(
                $runId,
                $item,
                $request,
                $strategy,
                new RepointReport((int) $item['target_id'], $request['canonicalId'], 0, [$e->getMessage()]),
                new CopyDisposition((int) $item['target_id'], DispositionOutcome::Blocked, $e->getMessage()),
                DuplicateMergePhase::Blocked,
                DuplicateMergePhase::Repointing,
            );
        } catch (\Throwable $e) {
            if (RetryableInfrastructureFailure::matches($e)) {
                throw $e;
            }

            $this->logger->error('Asset Pilot: duplicate merge copy {id} failed: {error}', [
                'id' => (int) $item['target_id'],
                'runId' => $runId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            $this->completeItem(
                $runId,
                $item,
                $request,
                $strategy,
                $this->reportFromState($item, $request['canonicalId']),
                new CopyDisposition((int) $item['target_id'], DispositionOutcome::LeftError, 'The copy could not be merged.'),
                DuplicateMergePhase::Failed,
                $this->resumePhase($item),
            );
        }
    }

    /** @param list<array<string, mixed>> $items */
    private function cancelAvailableItems(string $runId, array $items): void
    {
        foreach ($items as $item) {
            $itemKey = (string) $item['item_key'];
            if (!$this->loopGuard->acquireOperationRunItem($runId, $itemKey)) {
                continue;
            }
            try {
                $this->runs->completeItem(
                    $runId,
                    $itemKey,
                    OperationRunItemStatus::Cancelled,
                    error: 'Cancellation was requested before processing.',
                );
            } finally {
                $this->loopGuard->releaseOperationRunItem($runId, $itemKey);
            }
        }
    }

    /**
     * @param array<string, mixed> $item
     * @param array{assetIds: list<int>, canonicalId: int, checksum: string, strategy: string, reviewedFingerprints: array<string, string>} $request
     */
    private function processItem(
        string $runId,
        array $item,
        array $request,
        DuplicateMergeStrategyInterface $strategy,
        DuplicateMergeContextInterface $context,
    ): void {
        $copyId = (int) $item['target_id'];
        $phase = $this->resumePhase($item);
        $report = $this->reportFromState($item, $request['canonicalId']);

        if ($phase === DuplicateMergePhase::Committed) {
            $committed = $this->dispositionFromState($item);
            if ($committed === null) {
                throw new \RuntimeException('A committed duplicate merge item has no persisted disposition.');
            }
            $this->completeItem($runId, $item, $request, $strategy, $report, $committed, DuplicateMergePhase::Committed);

            return;
        }

        if ($phase === DuplicateMergePhase::Disposing) {
            $recovered = $strategy instanceof ResumableDuplicateMergeStrategyInterface
                ? $strategy->recoverDisposition($copyId, $report)
                : null;
            if ($recovered !== null) {
                $this->completeItem($runId, $item, $request, $strategy, $report, $recovered, DuplicateMergePhase::Committed);

                return;
            }
            if (!$strategy instanceof ResumableDuplicateMergeStrategyInterface) {
                $this->completeItem(
                    $runId,
                    $item,
                    $request,
                    $strategy,
                    $report,
                    new CopyDisposition($copyId, DispositionOutcome::Blocked, 'The custom strategy cannot verify an interrupted disposition safely.'),
                    DuplicateMergePhase::Blocked,
                    DuplicateMergePhase::Disposing,
                );

                return;
            }
        }

        $current = $this->repointer->preflight($copyId, $request['canonicalId'], 'publish');
        $this->assertItemSafe($item, $request, $current, $phase, $strategy);

        if (!in_array($phase, [DuplicateMergePhase::Repointed, DuplicateMergePhase::Disposing], true)) {
            $this->persistState($runId, $item, DuplicateMergePhase::Repointing, $report);
            $report = $strategy->repointsReferences()
                ? $this->repointer->repoint($copyId, $request['canonicalId'])
                : new RepointReport($copyId, $request['canonicalId'], 0, []);
            $this->persistState($runId, $item, DuplicateMergePhase::Repointed, $report);
        }

        if (!$report->fullyRepointed) {
            $reason = sprintf('%d reference(s) could not be repointed: %s', count($report->blocked), implode('; ', $report->blocked));
            $this->completeItem(
                $runId,
                $item,
                $request,
                $strategy,
                $report,
                new CopyDisposition($copyId, DispositionOutcome::Blocked, $reason),
                DuplicateMergePhase::Blocked,
                DuplicateMergePhase::Repointing,
            );

            return;
        }

        $this->assertDispositionSafe($item, $request, $strategy);
        $this->persistState($runId, $item, DuplicateMergePhase::Disposing, $report);
        $this->loopGuard->refreshAsset($copyId);
        $disposition = $strategy->disposeCopy($report, $context);
        $terminalPhase = match ($this->itemStatus($disposition)) {
            OperationRunItemStatus::Completed => DuplicateMergePhase::Committed,
            OperationRunItemStatus::Blocked, OperationRunItemStatus::Skipped => DuplicateMergePhase::Blocked,
            default => DuplicateMergePhase::Failed,
        };
        $this->completeItem(
            $runId,
            $item,
            $request,
            $strategy,
            $report,
            $disposition,
            $terminalPhase,
            $terminalPhase === DuplicateMergePhase::Committed ? null : DuplicateMergePhase::Disposing,
        );
    }

    /**
     * @param array<string, mixed> $item
     * @param array{assetIds: list<int>, canonicalId: int, checksum: string, strategy: string, reviewedFingerprints: array<string, string>} $request
     */
    private function assertItemSafe(
        array $item,
        array $request,
        RepointPreflight $current,
        DuplicateMergePhase $phase,
        DuplicateMergeStrategyInterface $strategy,
    ): void {
        $copyId = (int) $item['target_id'];
        $this->assertLiveChecksums([$request['canonicalId'], $copyId], $request['checksum']);
        $this->assertGroupAuthorized([$request['canonicalId'], $copyId], 'publish');
        if ($this->assetIsProtected($copyId)) {
            throw new StaleApplyPlanException(sprintf('Duplicate copy %d is protected from automated changes.', $copyId));
        }

        $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
        if (!hash_equals((string) ($payload['canonicalFingerprint'] ?? ''), $this->assetIdentityFingerprint($request['canonicalId']))) {
            throw new StaleApplyPlanException('The canonical asset changed after the merge run was prepared.');
        }
        if (!hash_equals((string) ($item['fingerprint'] ?? ''), $this->assetIdentityFingerprint($copyId))) {
            throw new StaleApplyPlanException(sprintf('Duplicate copy %d changed after the merge run was prepared.', $copyId));
        }

        $planned = $this->storedReferrers($item);
        $currentByKey = $current->referrersByKey();
        foreach ($currentByKey as $key => $snapshot) {
            if (!isset($planned[$key]) || !hash_equals($planned[$key]->fingerprint, $snapshot->fingerprint)) {
                throw new StaleApplyPlanException(sprintf('Referrer %s changed after the merge run was prepared.', $key));
            }
        }
        if ($phase === DuplicateMergePhase::Prepared && array_keys($planned) !== array_keys($currentByKey)) {
            throw new StaleApplyPlanException('Duplicate dependencies changed after the merge run was prepared.');
        }

        if ($current->blocked !== []) {
            throw new StaleApplyPlanException(implode('; ', $current->blocked));
        }
        if ($strategy->repointsReferences() && $phase === DuplicateMergePhase::Repointed && $current->referrers !== []) {
            throw new StaleApplyPlanException('The copy is still referenced after repointing.');
        }
    }

    /**
     * @param array<string, mixed> $item
     * @param array{assetIds: list<int>, canonicalId: int, checksum: string, strategy: string, reviewedFingerprints: array<string, string>} $request
     */
    private function assertDispositionSafe(
        array $item,
        array $request,
        DuplicateMergeStrategyInterface $strategy,
    ): void {
        $copyId = (int) $item['target_id'];
        $this->assertLiveChecksums([$request['canonicalId'], $copyId], $request['checksum']);
        $this->assertGroupAuthorized([$request['canonicalId'], $copyId], 'publish');

        if (!hash_equals((string) ($item['fingerprint'] ?? ''), $this->assetIdentityFingerprint($copyId))) {
            throw new StaleApplyPlanException(sprintf('Duplicate copy %d changed before disposition.', $copyId));
        }
        if ($strategy->repointsReferences()) {
            $preflight = $this->repointer->preflight($copyId, $request['canonicalId'], 'publish');
            if ($preflight->referrers !== [] || $preflight->blocked !== []) {
                throw new StaleApplyPlanException('The copy is still referenced after repointing; it was not disposed.');
            }
        }
    }

    /**
     * @param array<string, mixed> $item
     * @param array{assetIds: list<int>, canonicalId: int, checksum: string, strategy: string, reviewedFingerprints: array<string, string>} $request
     */
    private function completeItem(
        string $runId,
        array $item,
        array $request,
        DuplicateMergeStrategyInterface $strategy,
        RepointReport $report,
        CopyDisposition $disposition,
        DuplicateMergePhase $phase,
        ?DuplicateMergePhase $resumePhase = null,
    ): void {
        $state = [
            'phase' => $phase->value,
            'repointReport' => $this->serializeReport($report),
            'disposition' => [
                'copyId' => $disposition->copyId,
                'outcome' => $disposition->outcome->value,
                'reason' => $disposition->reason,
            ],
        ];
        if ($resumePhase !== null) {
            $state['resumePhase'] = $resumePhase->value;
        }
        $this->runs->updateItemState(
            $runId,
            (string) $item['item_key'],
            $state,
            $this->loopGuard->operationRunItemToken($runId, (string) $item['item_key']),
        );

        $status = $this->itemStatus($disposition);
        $completed = $this->runs->completeItem(
            $runId,
            (string) $item['item_key'],
            $status,
            [
                'copyId' => $disposition->copyId,
                'outcome' => $disposition->outcome->value,
                'reason' => $disposition->reason,
                'repointReport' => $this->serializeReport($report),
            ],
            $status === OperationRunItemStatus::Failed ? $disposition->reason : null,
            $this->loopGuard->operationRunItemToken($runId, (string) $item['item_key']),
        );
        if (!$completed || $status !== OperationRunItemStatus::Completed) {
            return;
        }

        NonFatalEventDispatcher::dispatch(
            $this->eventDispatcher,
            new DuplicateMergeEvent(
                $runId,
                $request['checksum'],
                $request['canonicalId'],
                $strategy->name(),
                $disposition,
                $report,
                $status,
            ),
            AssetPilotEvents::DUPLICATE_MERGE_COMMITTED,
            $this->logger,
            ['runId' => $runId, 'copyId' => $disposition->copyId],
        );
    }

    /**
     * @param list<int> $assetIds
     * @param list<array<string, mixed>> $items
     * @param array<int, RepointPreflight> $currentPreflights
     * @return list<array{type: string, id: int}>|null
     */
    private function acquireLocks(array $assetIds, array $items, array $currentPreflights): ?array
    {
        $resources = [];
        foreach ($assetIds as $assetId) {
            $resources['asset:' . $assetId] = ['type' => 'asset', 'id' => $assetId];
        }
        foreach ($items as $item) {
            foreach ($this->storedReferrers($item) as $snapshot) {
                $resources[$snapshot->key()] = ['type' => $snapshot->type, 'id' => $snapshot->id];
            }
            $copyId = (int) $item['target_id'];
            foreach (($currentPreflights[$copyId] ?? new RepointPreflight($copyId, 0, []))->referrers as $snapshot) {
                $resources[$snapshot->key()] = ['type' => $snapshot->type, 'id' => $snapshot->id];
            }
        }
        ksort($resources, SORT_STRING);

        $locked = [];
        foreach ($resources as $resource) {
            $acquired = $resource['type'] === 'asset'
                ? $this->loopGuard->acquireAsset($resource['id'])
                : $this->loopGuard->acquireReferrer($resource['type'], $resource['id']);
            if (!$acquired) {
                $this->releaseLocks($locked);

                return null;
            }
            $locked[] = $resource;
        }

        return $locked;
    }

    /** @param list<array{type: string, id: int}> $locks */
    private function refreshLocks(array $locks): void
    {
        foreach ($locks as $resource) {
            if ($resource['type'] === 'asset') {
                $this->loopGuard->refreshAsset($resource['id']);
            } else {
                $this->loopGuard->refreshReferrer($resource['type'], $resource['id']);
            }
        }
    }

    /**
     * @param array<string, mixed> $item
     * @param array{assetIds: list<int>, canonicalId: int, checksum: string, strategy: string, reviewedFingerprints: array<string, string>} $request
     * @param list<array{type: string, id: int}> $locks
     */
    private function mergeContext(string $runId, array $item, array $request, array $locks): DuplicateMergeContextInterface
    {
        $itemKey = (string) $item['item_key'];
        $copyId = (int) $item['target_id'];

        return new DuplicateMergeContext(
            $runId,
            $itemKey,
            $copyId,
            $request['canonicalId'],
            (int) ($item['attempts'] ?? 1),
            $this->authorization->currentActor(),
            // Anchor the external idempotency key to the ROOT run of the retry_of chain, not the current run:
            // a retry creates a new child run, so keying on the current id would change the key across attempts
            // and let an external deduplicator repeat a committed side effect. The root is stable across
            // attempt/resume/retry and new for a separately reviewed merge.
            sprintf('duplicate-merge:%s:%s:%d', $this->runs->rootId($runId), $request['checksum'], $copyId),
            function () use ($runId, $itemKey, $copyId, $locks): void {
                try {
                    $this->loopGuard->refreshOperationRunItem($runId, $itemKey);
                    $this->refreshLocks($locks);
                    $this->loopGuard->refreshAsset($copyId);
                } catch (LockConflictedException $e) {
                    throw new MergeLeaseLostException('A duplicate-merge lock was lost during disposition; aborting to avoid a double disposition.', 0, $e);
                }
                $token = $this->loopGuard->operationRunItemToken($runId, $itemKey);
                if ($token !== null && !$this->runs->renewItemLease($runId, $itemKey, $token)) {
                    throw new MergeLeaseLostException('The duplicate-merge run-item lease was lost during disposition; aborting to avoid a double disposition.');
                }
            },
            fn (callable $mutator) => $this->guardedMergeSave($copyId, $mutator),
        );
    }

    /** @param callable(Asset): void $mutator */
    private function guardedMergeSave(int $copyId, callable $mutator): void
    {
        $asset = $this->loadAsset($copyId);
        if ($asset === null) {
            throw new \RuntimeException(sprintf('Duplicate copy asset %d is no longer available to save.', $copyId));
        }
        $this->loopGuard->markAssetProcessing($copyId);
        try {
            $this->loopGuard->refreshAsset($copyId);
            $mutator($asset);
            $this->saveAsset($asset);
        } finally {
            $this->loopGuard->unmarkAssetProcessing($copyId);
        }
    }

    /** @param list<array{type: string, id: int}> $locks */
    private function releaseLocks(array $locks): void
    {
        foreach (array_reverse($locks) as $resource) {
            if ($resource['type'] === 'asset') {
                $this->loopGuard->releaseAsset($resource['id']);
            } else {
                $this->loopGuard->releaseReferrer($resource['type'], $resource['id']);
            }
        }
    }

    /** @param list<int> $assetIds */
    private function assertLiveChecksums(array $assetIds, string $checksum): void
    {
        foreach ($assetIds as $assetId) {
            if ($this->liveChecksum($assetId) !== $checksum) {
                $this->forgetStaleChecksum($assetId);
                throw new StaleApplyPlanException(sprintf('Asset %d is no longer byte-identical to the prepared duplicate group.', $assetId));
            }
        }
    }

    protected function liveChecksum(int $assetId): ?string
    {
        $checksum = $this->loadAsset($assetId)?->getChecksum();

        return $checksum === null || $checksum === '' ? null : $checksum;
    }

    protected function loadAsset(int $assetId): ?Asset
    {
        $asset = Asset::getById($assetId, ['force' => true]);

        return $asset instanceof Asset && !$asset instanceof Asset\Folder ? $asset : null;
    }

    protected function saveAsset(Asset $asset): void
    {
        $asset->save();
    }

    protected function assetAllows(int $assetId, string $permission): bool
    {
        $asset = $this->loadAsset($assetId);

        return $asset !== null && $this->authorization->isAllowed($asset, $permission);
    }

    /** @param list<int> $assetIds */
    private function assertGroupAuthorized(array $assetIds, string $permission): void
    {
        foreach ($assetIds as $assetId) {
            if (!$this->assetAllows($assetId, $permission)) {
                throw new NotPermittedException(sprintf('Not permitted to merge duplicate asset %d.', $assetId));
            }
        }
    }

    protected function assetIdentityFingerprint(int $assetId): string
    {
        $asset = $this->loadAsset($assetId);
        if ($asset === null) {
            return hash('sha256', 'missing');
        }

        return $this->hash([
            'checksum' => $asset->getChecksum(),
            'locked' => $this->assetIsProtected($assetId),
            'modifiedAt' => $asset->getModificationDate(),
            'path' => $asset->getRealFullPath(),
        ]);
    }

    protected function assetIsProtected(int $assetId): bool
    {
        $asset = $this->loadAsset($assetId);

        return $asset !== null && AssetProtection::isLocked($asset, $this->lockProperty);
    }

    /** @param array<string, mixed> $item @return array<string, ReferrerSnapshot> */
    private function storedReferrers(array $item): array
    {
        $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
        $rows = is_array($payload['referrers'] ?? null) ? $payload['referrers'] : [];
        $snapshots = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $snapshot = ReferrerSnapshot::fromArray($row);
            $snapshots[$snapshot->key()] = $snapshot;
        }
        ksort($snapshots, SORT_STRING);

        return $snapshots;
    }

    /** @param array<string, mixed> $item */
    private function reportFromState(array $item, int $canonicalId): RepointReport
    {
        $state = is_array($item['state_payload'] ?? null) ? $item['state_payload'] : [];
        $report = is_array($state['repointReport'] ?? null) ? $state['repointReport'] : [];

        return new RepointReport(
            (int) $item['target_id'],
            $canonicalId,
            (int) ($report['repointedObjects'] ?? 0),
            array_values(array_filter(is_array($report['blocked'] ?? null) ? $report['blocked'] : [], 'is_string')),
        );
    }

    /** @param array<string, mixed> $item */
    private function dispositionFromState(array $item): ?CopyDisposition
    {
        $state = is_array($item['state_payload'] ?? null) ? $item['state_payload'] : [];
        $data = is_array($state['disposition'] ?? null) ? $state['disposition'] : [];
        $outcome = DispositionOutcome::tryFrom((string) ($data['outcome'] ?? ''));
        if ($outcome === null) {
            return null;
        }

        return new CopyDisposition(
            (int) ($data['copyId'] ?? $item['target_id']),
            $outcome,
            (string) ($data['reason'] ?? ''),
        );
    }

    /** @param array<string, mixed> $item */
    private function resumePhase(array $item): DuplicateMergePhase
    {
        $state = is_array($item['state_payload'] ?? null) ? $item['state_payload'] : [];
        $phase = DuplicateMergePhase::tryFrom((string) ($state['phase'] ?? '')) ?? DuplicateMergePhase::Prepared;
        if (in_array($phase, [DuplicateMergePhase::Blocked, DuplicateMergePhase::Failed], true)) {
            return DuplicateMergePhase::tryFrom((string) ($state['resumePhase'] ?? '')) ?? DuplicateMergePhase::Prepared;
        }

        return $phase;
    }

    private function persistState(
        string $runId,
        array $item,
        DuplicateMergePhase $phase,
        RepointReport $report,
    ): void {
        $itemKey = (string) $item['item_key'];
        $this->loopGuard->refreshOperationRunItem($runId, $itemKey);
        $token = $this->loopGuard->operationRunItemToken($runId, $itemKey);
        if ($token !== null && !$this->runs->renewItemLease($runId, $itemKey, $token)) {
            throw new MergeLeaseLostException('The duplicate-merge run-item lease was lost while persisting phase state; aborting to avoid a double disposition.');
        }
        if (!$this->runs->updateItemState($runId, $itemKey, [
            'phase' => $phase->value,
            'repointReport' => $this->serializeReport($report),
        ], $token)) {
            throw new \RuntimeException('Duplicate merge item state could not be persisted.');
        }
    }

    /** @return array{fromAssetId: int, toAssetId: int, repointedObjects: int, blocked: list<string>} */
    private function serializeReport(RepointReport $report): array
    {
        return [
            'fromAssetId' => $report->fromAssetId,
            'toAssetId' => $report->toAssetId,
            'repointedObjects' => $report->repointedObjects,
            'blocked' => $report->blocked,
        ];
    }

    private function itemStatus(CopyDisposition $disposition): OperationRunItemStatus
    {
        return match ($disposition->outcome) {
            DispositionOutcome::Deleted, DispositionOutcome::Quarantined => OperationRunItemStatus::Completed,
            DispositionOutcome::Blocked, DispositionOutcome::LeftReferenced => OperationRunItemStatus::Blocked,
            DispositionOutcome::Skipped => OperationRunItemStatus::Skipped,
            DispositionOutcome::LeftError => OperationRunItemStatus::Failed,
        };
    }

    /** @param array<string, mixed> $run */
    private function outcomeFromRun(array $run): MergeOutcome
    {
        $request = $this->requestFromRun($run);
        $dispositions = [];
        foreach ($run['items'] as $item) {
            $result = is_array($item['result_payload'] ?? null) ? $item['result_payload'] : [];
            $outcome = DispositionOutcome::tryFrom((string) ($result['outcome'] ?? ''));
            $dispositions[] = new CopyDisposition(
                (int) $item['target_id'],
                $outcome ?? DispositionOutcome::Blocked,
                $outcome === null ? 'The copy is pending; resume this merge run.' : (string) ($result['reason'] ?? ''),
            );
        }

        return new MergeOutcome(
            $request['checksum'],
            $request['canonicalId'],
            $dispositions,
            (string) $run['id'],
            OperationRunStatus::from((string) $run['status']),
        );
    }

    private function outcomeFromRequiredRun(string $runId): MergeOutcome
    {
        $run = $this->runs->get($runId, $this->authorization->currentActor());
        if ($run === null) {
            throw new \LogicException('A duplicate merge run disappeared while it was executing.');
        }

        return $this->outcomeFromRun($run);
    }

    /**
     * @param array<string, mixed> $run
     * @return array{assetIds: list<int>, canonicalId: int, checksum: string, strategy: string, reviewedFingerprints: array<string, string>}
     */
    private function requestFromRun(array $run): array
    {
        $request = is_array($run['request_payload'] ?? null) ? $run['request_payload'] : [];
        $assetIds = array_values(array_unique(array_filter(
            array_map('intval', is_array($request['assetIds'] ?? null) ? $request['assetIds'] : []),
            static fn (int $assetId): bool => $assetId > 0,
        )));
        $canonicalId = (int) ($request['canonicalId'] ?? 0);
        $checksum = (string) ($request['checksum'] ?? '');
        $strategy = (string) ($request['strategy'] ?? '');
        $reviewedFingerprints = is_array($request['reviewedFingerprints'] ?? null)
            ? $request['reviewedFingerprints']
            : [];
        $expectedFingerprintKeys = array_map($this->itemKey(...), $assetIds);
        $reviewedFingerprintKeys = array_keys($reviewedFingerprints);
        sort($expectedFingerprintKeys, SORT_STRING);
        sort($reviewedFingerprintKeys, SORT_STRING);
        $hasInvalidFingerprint = array_any(
            $reviewedFingerprints,
            static fn (mixed $fingerprint): bool => !is_string($fingerprint)
                || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1,
        );
        if ($assetIds === []
            || !in_array($canonicalId, $assetIds, true)
            || $checksum === ''
            || $strategy === ''
            || $hasInvalidFingerprint
            || $reviewedFingerprintKeys !== $expectedFingerprintKeys
        ) {
            throw new \RuntimeException('The persisted duplicate merge selector is invalid.');
        }

        return [
            'assetIds' => $assetIds,
            'canonicalId' => $canonicalId,
            'checksum' => $checksum,
            'strategy' => $strategy,
            'reviewedFingerprints' => $reviewedFingerprints,
        ];
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

    private function forgetStaleChecksum(int $assetId): void
    {
        try {
            $this->connection->delete(Installer::TABLE_CHECKSUM, ['asset_id' => $assetId]);
        } catch (\Throwable $e) {
            $this->logger->warning('Asset Pilot: could not drop stale checksum row for asset {id}: {error}', [
                'id' => $assetId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /** @param array<string, mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function itemKey(int $copyId): string
    {
        return 'asset:' . $copyId;
    }
}
