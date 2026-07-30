<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Oronts\AssetPilotBundle\Cache\StatsCache;
use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Enum\ConfidenceLevel;
use Oronts\AssetPilotBundle\Enum\DependencyUsageVerdict;
use Oronts\AssetPilotBundle\Event\AssetMutationEvent;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Event\NonFatalEventDispatcher;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Service\Query\AssetFolders;
use Oronts\AssetPilotBundle\Service\Query\AssetSortColumns;
use Oronts\AssetPilotBundle\Service\Query\AssetWorkspaceQueryScope;
use Oronts\AssetPilotBundle\Service\Query\AuthorizedAssetPage;
use Oronts\AssetPilotBundle\Service\Query\ByteFormat;
use Oronts\AssetPilotBundle\Service\Query\ConfidenceFilter;
use Oronts\AssetPilotBundle\Service\Query\DateFilters;
use Oronts\AssetPilotBundle\Service\Query\IndexedAssetSize;
use Oronts\AssetPilotBundle\Service\Query\Like;
use Oronts\AssetPilotBundle\Service\Query\PimcoreSchema;
use Oronts\AssetPilotBundle\Service\Query\SortWhitelist;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class UnusedAssetFinder implements UnusedAssetFinderInterface
{
    use AppliesReviewedPlanLocks;
    private const string UNUSED_STATS_CACHE_KEY = 'asset_pilot.unused_stats';

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly ConfidenceScorerInterface $scorer,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ContentUsageScannerInterface $contentScanner,
        private readonly LoopGuard $loopGuard,
        private readonly ReviewedAssetLockCoordinator $reviewedLocks,
        private readonly ElementAuthorizationInterface $authorization,
        private readonly DependencyUsageVerifierInterface $dependencyVerifier,
        private readonly AssetWorkspaceQueryScope $workspaceScope,
        private readonly AuthorizedAssetPage $authorizedPage,
        private readonly AssetMutationFingerprintService $mutationFingerprints,
        private readonly LoopGuardedAssetSaver $assetSaver,
        private readonly AssetDeletionFenceInterface $deletionFence,
        private readonly string $lockProperty = AssetProtection::DEFAULT_LOCK_PROPERTY,
        private readonly ?StatsCache $statsCache = null,
        private readonly int $statsTtl = 0,
    ) {}

    /**
     * Find assets not referenced by any object/document via the dependencies table.
     *
     * @param array{
     *     type?: string|string[],
     *     extension?: string|string[],
     *     before?: string,
     *     after?: string,
     *     folder?: string,
     *     confidence?: string,
     * } $filters
     */
    public function findUnused(array $filters = [], int $page = 1, int $limit = 50, ?string $sort = null, ?string $order = null): array
    {
        $this->rejectUnsupportedSizeFilters($filters);
        DateFilters::validate($filters);
        $this->validateConfidenceFilter($filters);

        [$sortColumn, $sortDir] = SortWhitelist::resolve($sort, $order, AssetSortColumns::MAP, AssetSortColumns::DEFAULT);

        try {
            $result = $this->authorizedPage->paginate(
                $page,
                $limit,
                exactTotal: function () use ($filters): int {
                    $countQb = $this->connection->createQueryBuilder()
                        ->select('COUNT(*) as total')
                        ->from(PimcoreSchema::TABLE_ASSETS, 'a');
                    $this->applyUnusedPredicate($countQb);
                    $this->applyFilters($countQb, $filters);
                    $this->workspaceScope->applyView($countQb, 'a', 'unusedCount');

                    return (int) $countQb->executeQuery()->fetchOne();
                },
                window: $this->unusedWindow($filters, $sortColumn, $sortDir),
                assetIdOf: static fn (array $row): ?int => isset($row['id']) ? (int) $row['id'] : null,
            );
            $total = $result['total'];

            return [
                'items' => $result['items'],
                'total' => $total,
                'page' => max(1, $page),
                'pages' => $total === null ? null : ($limit > 0 ? (int) ceil($total / max(1, $limit)) : 0),
                'hasMore' => $result['hasMore'],
                'truncated' => $result['truncated'],
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to find unused assets: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return ['items' => [], 'total' => 0, 'page' => max(1, $page), 'pages' => 0, 'hasMore' => false, 'truncated' => false];
        }
    }

    /**
     * The raw workspace-scoped unused-asset window (offset, limit), with a unique a.id tiebreak so offset
     * paging is stable.
     *
     * @param array<string, mixed> $filters
     *
     * @return callable(int, int): list<array<string, mixed>>
     */
    private function unusedWindow(array $filters, string $sortColumn, string $sortDir): callable
    {
        return function (int $offset, int $limit) use ($filters, $sortColumn, $sortDir): array {
            $qb = $this->connection->createQueryBuilder()
                ->select('a.id, a.path, a.filename, a.type, a.mimetype, a.creationDate as created_at, a.modificationDate as modified_at')
                ->addSelect('CASE WHEN lp.data = \'1\' THEN 1 ELSE 0 END as locked')
                ->from(PimcoreSchema::TABLE_ASSETS, 'a')
                ->leftJoin('a', PimcoreSchema::TABLE_PROPERTIES, 'lp', 'lp.cid = a.id AND lp.ctype = :lock_ctype AND lp.name = :lock_prop')
                ->setParameter('lock_ctype', PimcoreSchema::ELEMENT_TYPE_ASSET)
                ->setParameter('lock_prop', $this->lockProperty)
                ->orderBy($sortColumn, $sortDir)
                ->addOrderBy('a.id', $sortDir)
                ->setFirstResult($offset)
                ->setMaxResults($limit);
            IndexedAssetSize::join($qb);
            $this->applyUnusedPredicate($qb);
            $this->applyFilters($qb, $filters);
            $this->workspaceScope->applyView($qb, 'a', 'unusedList');

            return $this->hydrateRows($qb->executeQuery()->fetchAllAssociative());
        };
    }

    /**
     * Stream every natively-visible unused asset for a CSV export. The generator return value is `true` when
     * the export was cut short by the row ceiling, so the caller can read `->getReturn()` and flag it.
     *
     * @param array<string, mixed> $filters
     *
     * @return \Generator<int, array<string, mixed>, mixed, bool>
     */
    public function iterateForExport(array $filters = [], ?string $sort = null, ?string $order = null): \Generator
    {
        $this->rejectUnsupportedSizeFilters($filters);
        DateFilters::validate($filters);
        $this->validateConfidenceFilter($filters);
        [$sortColumn, $sortDir] = SortWhitelist::resolve($sort, $order, AssetSortColumns::MAP, AssetSortColumns::DEFAULT);

        return yield from $this->authorizedPage->iterateAuthorized(
            $this->unusedWindow($filters, $sortColumn, $sortDir),
            static fn (array $row): ?int => isset($row['id']) ? (int) $row['id'] : null,
        );
    }

    /** @param list<array<string, mixed>> $items @return list<array<string, mixed>> */
    private function hydrateRows(array $items): array
    {
        foreach ($items as &$item) {
            $item['file_size'] = IndexedAssetSize::bytes($item);
            $item['size_known'] = $item['file_size'] !== null;
            $item['created_at'] = $item['created_at'] ? gmdate(\DateTimeInterface::ATOM, (int) $item['created_at']) : null;
            $item['modified_at'] = $item['modified_at'] ? gmdate(\DateTimeInterface::ATOM, (int) $item['modified_at']) : null;
            $item['full_path'] = rtrim((string) ($item['path'] ?? ''), '/') . '/' . ($item['filename'] ?? '');
            $item['locked'] = (bool) ($item['locked'] ?? false);
            unset($item['indexed_file_size'], $item['indexed_size_known'], $item['size_indexed_at']);
        }

        return $this->scorer->score($items);
    }

    public function countUnused(array $filters = []): int
    {
        $this->rejectUnsupportedSizeFilters($filters);
        DateFilters::validate($filters);
        $this->validateConfidenceFilter($filters);

        try {
            $qb = $this->connection->createQueryBuilder()
                ->select('COUNT(*) as total')
                ->from(PimcoreSchema::TABLE_ASSETS, 'a');
            $this->applyUnusedPredicate($qb);

            $this->applyFilters($qb, $filters);
            $this->workspaceScope->applyView($qb, 'a', 'unusedCount');

            return (int) $qb->executeQuery()->fetchOne();
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to count unused assets: {error}', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Web-path stats: served from the short-TTL cache (the unbounded scan + per-asset storage stat is
     * too costly to run on every request). getUnusedStats() stays uncached for the schedule capture,
     * which needs live numbers. The cache is busted on the bundle's own delete/move.
     */
    public function getUnusedStatsCached(): array
    {
        if ($this->authorization->currentActor()->type !== ActorType::System) {
            return $this->getUnusedStats();
        }

        return $this->statsCache?->remember(self::UNUSED_STATS_CACHE_KEY, $this->statsTtl, fn (): array => $this->getUnusedStats())
            ?? $this->getUnusedStats();
    }

    public function getUnusedStats(): array
    {
        try {
            // The assets table has no size column, so real byte totals require reading each unused
            // asset's size from storage by path. This is an on-demand panel, not a hot path.
            $qb = $this->connection->createQueryBuilder()
                ->select('a.id', 'a.type', 'a.path', 'a.filename', 'a.modificationDate AS modified_at')
                ->from(PimcoreSchema::TABLE_ASSETS, 'a');
            IndexedAssetSize::join($qb);
            $this->applyUnusedPredicate($qb);
            $this->workspaceScope->applyView($qb, 'a', 'unusedStats');

            return $this->aggregateStats($qb->executeQuery()->iterateAssociative());
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: failed to get unused asset stats: {error}', [
                'error' => $e->getMessage(),
            ]);

            return ['totalCount' => 0, 'totalSize' => 0, 'totalSizeFormatted' => ByteFormat::human(0), 'unknownSizeCount' => 0, 'byType' => []];
        }
    }

    /**
     * @param iterable<array{id: mixed, type: mixed, path: mixed, filename: mixed}> $rows
     * @return array{totalCount: int, totalSize: int, totalSizeFormatted: string, unknownSizeCount: int, byType: list<array{type: string, count: int, total_size: int, unknown_size_count: int}>}
     */
    protected function aggregateStats(iterable $rows): array
    {
        $totalSize = 0;
        $totalCount = 0;
        $unknownSizeCount = 0;
        $byType = [];
        foreach ($rows as $row) {
            ++$totalCount;
            $type = (string) $row['type'];
            $size = array_key_exists('indexed_file_size', $row)
                ? IndexedAssetSize::bytes($row)
                : $this->fileSize(rtrim((string) ($row['path'] ?? ''), '/') . '/' . ((string) ($row['filename'] ?? '')));
            $byType[$type] ??= ['count' => 0, 'total_size' => 0, 'unknown_size_count' => 0];
            $byType[$type]['count']++;
            if ($size === null) {
                ++$byType[$type]['unknown_size_count'];
                ++$unknownSizeCount;
                continue;
            }
            $byType[$type]['total_size'] += $size;
            $totalSize += $size;
        }

        uasort($byType, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $byTypeList = [];
        foreach ($byType as $type => $agg) {
            $byTypeList[] = ['type' => $type, 'count' => $agg['count'], 'total_size' => $agg['total_size'], 'unknown_size_count' => $agg['unknown_size_count']];
        }

        return [
            'totalCount' => $totalCount,
            'totalSize' => $totalSize,
            'totalSizeFormatted' => ByteFormat::human($totalSize),
            'unknownSizeCount' => $unknownSizeCount,
            'byType' => $byTypeList,
        ];
    }

    protected function fileSize(string $fullPath): ?int
    {
        return null;
    }

    protected function loadAsset(int $id): ?Asset
    {
        return Asset::getById($id);
    }

    protected function createTargetFolder(string $path): Asset\Folder
    {
        return Asset\Service::createFolderByPath($path);
    }

    protected function nearestExistingFolder(string $path): ?Asset\Folder
    {
        return AssetFolders::nearestExisting($path);
    }

    /**
     * @param int[] $assetIds
     * @return array{deleted: int, failed: int, errors: array<int, string>, observerWarnings: list<string>}
     */
    public function deleteAssets(array $assetIds, ?array $expectedFingerprints = null): array
    {
        [$deletedIds, $failed, $errors] = $this->withReviewedPlanLocks(
            $assetIds,
            $expectedFingerprints,
            fn (array $lockedIds): array => $this->deleteAssetsLocked($assetIds, $lockedIds),
        );

        $observerWarnings = [];
        if ($deletedIds !== []) {
            $this->statsCache?->delete(self::UNUSED_STATS_CACHE_KEY);
            if (NonFatalEventDispatcher::dispatch(
                $this->eventDispatcher,
                new AssetMutationEvent($deletedIds, 'unused_delete'),
                AssetPilotEvents::UNUSED_DELETED,
                $this->logger,
            ) !== []) {
                $observerWarnings[] = 'Unused-delete observer delivery failed.';
            }
        }

        return ['deleted' => count($deletedIds), 'failed' => $failed, 'errors' => $errors, 'observerWarnings' => $observerWarnings];
    }

    /**
     * @param int[] $assetIds
     * @return array{moved: int, failed: int, errors: array<int, string>, observerWarnings: list<string>}
     */
    public function moveAssets(array $assetIds, string $targetFolder, ?array $expectedFingerprints = null): array
    {
        $movedIds = [];
        $failed = 0;
        $errors = [];

        if (!$this->hasContentEvidence()) {
            return ['moved' => 0, 'failed' => count($assetIds), 'errors' => [-1 => 'Content-reference verification is not configured; refusing to move assets'], 'observerWarnings' => []];
        }

        // Authorize before creating anything: createFolderByPath would create the whole target tree as
        // a side effect, so check the create ACL on the nearest existing ancestor first (Pimcore's
        // workspace ACL for placing an element). The actor-aware check allows System actors
        // (CLI/maintenance) and enforces the real workspace ACL for a resolved web user.
        $parent = $this->nearestExistingFolder($targetFolder);
        $targetAllowed = $parent === null || $this->authorization->isAllowed($parent, 'create');
        if (!$targetAllowed) {
            return ['moved' => 0, 'failed' => count($assetIds), 'errors' => [-1 => 'Not permitted to move assets into the target folder'], 'observerWarnings' => []];
        }

        [$movedIds, $failed, $errors] = $this->withReviewedPlanLocks(
            $assetIds,
            $expectedFingerprints,
            function (array $lockedIds) use ($assetIds, $targetFolder): array {
                try {
                    $folder = $this->createTargetFolder($targetFolder);
                } catch (\Throwable $e) {
                    $this->logger->error('Asset Pilot: failed to create unused-asset target folder', [
                        'target_folder' => $targetFolder,
                        'exception' => $e,
                    ]);

                    return [[], count($assetIds), [-1 => 'Failed to create the target folder.']];
                }

                return $this->moveAssetsLocked($assetIds, $targetFolder, $folder, $lockedIds);
            },
        );

        $observerWarnings = [];
        if ($movedIds !== []) {
            $this->statsCache?->delete(self::UNUSED_STATS_CACHE_KEY);
            if (NonFatalEventDispatcher::dispatch(
                $this->eventDispatcher,
                new AssetMutationEvent($movedIds, 'unused_move', ['targetFolder' => $targetFolder]),
                AssetPilotEvents::UNUSED_MOVED,
                $this->logger,
            ) !== []) {
                $observerWarnings[] = 'Unused-move observer delivery failed.';
            }
        }

        return ['moved' => count($movedIds), 'failed' => $failed, 'errors' => $errors, 'observerWarnings' => $observerWarnings];
    }

    /** @param int[] $assetIds @param list<int> $planLocks @return array{list<int>, int, array<int, string>} */
    private function deleteAssetsLocked(array $assetIds, array $planLocks): array
    {
        $deletedIds = [];
        $failed = 0;
        $errors = [];
        $planLockSet = array_fill_keys($planLocks, true);

        foreach ($assetIds as $id) {
            $id = (int) $id;
            $lockedByPlan = isset($planLockSet[$id]);
            if (!$lockedByPlan && !$this->loopGuard->acquireAsset($id)) {
                $errors[$id] = 'Asset is being processed by another job';
                ++$failed;
                continue;
            }

            $fenceToken = null;
            try {
                $fenceToken = $this->deletionFence->acquire($id, 'unused_delete');
                if ($fenceToken === null) {
                    $errors[$id] = 'Asset is already being deleted by another operation';
                    ++$failed;
                    continue;
                }

                [$asset, $error] = $this->guardMutation($id, 'delete', 'delete');
                if ($asset === null) {
                    $errors[$id] = (string) $error;
                    ++$failed;
                    continue;
                }

                $this->loopGuard->refreshAsset($id);
                $this->deletionFence->refreshOrFail($id, $fenceToken);
                $asset->delete();
                $deletedIds[] = $id;

                $this->logger->info('Asset Pilot: deleted unused asset {id} at {path}', [
                    'id' => $id,
                    'path' => $asset->getRealFullPath(),
                ]);
            } catch (\Throwable $e) {
                $errors[$id] = 'Failed to delete the asset.';
                ++$failed;
                $this->logger->error('Asset Pilot: failed to delete unused asset {id}', [
                    'id' => $id,
                    'exception' => $e,
                ]);
            } finally {
                // Best-effort fence release: the fence and the LoopGuard share this finally, so a throwing
                // fence DELETE must not strand the asset LoopGuard or abort the rest of the batch. A leaked
                // fence row is reaped by the maintenance task (expiry or the missing-asset branch).
                if ($fenceToken !== null) {
                    try {
                        $this->deletionFence->release($id, $fenceToken);
                    } catch (\Throwable $e) {
                        $this->logger->error('Asset Pilot: could not release the deletion fence for asset {id}; it will be reaped.', [
                            'id' => $id,
                            'exception' => $e,
                        ]);
                    }
                }
                if (!$lockedByPlan) {
                    $this->loopGuard->releaseAsset($id);
                }
            }
        }

        return [$deletedIds, $failed, $errors];
    }

    /** @param int[] $assetIds @param list<int> $planLocks @return array{list<int>, int, array<int, string>} */
    private function moveAssetsLocked(array $assetIds, string $targetFolder, Asset\Folder $folder, array $planLocks): array
    {
        $movedIds = [];
        $failed = 0;
        $errors = [];
        $planLockSet = array_fill_keys($planLocks, true);

        foreach ($assetIds as $id) {
            $id = (int) $id;
            $lockedByPlan = isset($planLockSet[$id]);
            if (!$lockedByPlan && !$this->loopGuard->acquireAsset($id)) {
                $errors[$id] = 'Asset is being processed by another job';
                ++$failed;
                continue;
            }

            $targetPath = null;
            try {
                [$asset, $error] = $this->guardMutation($id, 'publish', 'move');
                if ($asset === null) {
                    $errors[$id] = (string) $error;
                    ++$failed;
                    continue;
                }

                $targetPath = rtrim($targetFolder, '/') . '/' . basename($asset->getRealFullPath());
                if (!$this->loopGuard->acquireTarget($targetPath)) {
                    $errors[$id] = 'Target path is being allocated by another job';
                    ++$failed;
                    continue;
                }
                if (($occupant = $this->assetAtPath($targetPath)) !== null && (int) $occupant->getId() !== $id) {
                    $errors[$id] = 'Target path is occupied';
                    ++$failed;
                    continue;
                }

                $this->moveAsset($asset, $folder, $id, $targetPath, $targetFolder);
                $movedIds[] = $id;
            } catch (\Throwable $e) {
                $errors[$id] = 'Failed to move the asset.';
                ++$failed;
                $this->logger->error('Asset Pilot: failed to move unused asset {id}', ['id' => $id, 'exception' => $e]);
            } finally {
                if ($targetPath !== null) {
                    $this->loopGuard->releaseTarget($targetPath);
                }
                if (!$lockedByPlan) {
                    $this->loopGuard->releaseAsset($id);
                }
            }
        }

        return [$movedIds, $failed, $errors];
    }

    private function moveAsset(Asset $asset, Asset\Folder $folder, int $assetId, string $targetPath, string $targetFolder): void
    {
        $this->assetSaver->save(
            $asset,
            static function (Asset $mutable) use ($folder): void {
                $mutable->setParent($folder);
            },
            ['versionNote' => 'Asset Pilot: moved unused asset to ' . $targetFolder],
            function () use ($targetPath): void {
                $this->loopGuard->refreshTarget($targetPath);
            },
        );

        $this->logger->info('Asset Pilot: moved unused asset {id} to {path}', ['id' => $assetId, 'path' => $targetFolder]);
    }
    /**
     * Restrict a query on the `assets` table (alias `a`) to non-folder assets that no element
     * references. The shared predicate behind findUnused/countUnused/getUnusedStats.
     */
    protected function applyUnusedPredicate(QueryBuilder $qb): void
    {
        // NOT EXISTS (not NOT IN): null-safe and short-circuits. Distinct param name — filters['folder']
        // also binds :folder and would otherwise clobber this exclusion.
        $qb
            ->andWhere('a.type != :notFolderType')
            ->andWhere(sprintf(
                'NOT EXISTS (SELECT 1 FROM %s d WHERE d.targetid = a.id AND d.targettype = :assetType)',
                PimcoreSchema::TABLE_DEPENDENCIES,
            ))
            ->setParameter('notFolderType', PimcoreSchema::ASSET_TYPE_FOLDER)
            ->setParameter('assetType', PimcoreSchema::ELEMENT_TYPE_ASSET);
    }

    /**
     * Optional content-reference guard (opt-in, null when not configured): catches a hard-coded path
     * reference in rich-text/text fields that the dependency table does not track.
     */
    private function isReferencedInContent(Asset $asset): bool
    {
        return $this->contentScanner->freshlyReferencedInContent($asset);
    }

    /**
     * Shared pre-mutation guard for the destructive unused-asset paths. Both delete and move must
     * re-verify the same state (exists, not a folder, per-asset workspace ACL, still unreferenced,
     * not referenced in object content) so a single source keeps them from drifting. Returns the
     * loaded asset when it is safe to act on, or an error string to record against the id.
     *
     * @param 'delete'|'publish' $aclPermission Pimcore workspace ACL checked for the current user
     * @param 'delete'|'move'    $verb          used only for the human-readable error messages
     *
     * @return array{0: ?Asset, 1: ?string} [asset, null] when permitted, [null, error] otherwise
     */
    protected function guardMutation(int $id, string $aclPermission, string $verb): array
    {
        $asset = $this->loadAsset($id);
        if ($asset === null) {
            return [null, 'Asset not found'];
        }

        if ($asset instanceof Asset\Folder) {
            return [null, sprintf('Cannot %s folders', $verb)];
        }

        // Per-asset Pimcore workspace ACL (defence in depth over the flat operate permission).
        // The actor-aware authorization allows System actors (CLI/maintenance) and checks a resolved
        // web user against the workspace ACL.
        if (!$this->authorization->isAllowed($asset, $aclPermission)) {
            return [null, sprintf('Not permitted to %s this asset', $verb)];
        }

        // Locked assets are protected from any automated move/delete, even when unreferenced.
        if (AssetProtection::isLocked($asset, $this->lockProperty)) {
            return [null, 'Asset is locked'];
        }

        // Race-condition safety: it may have been referenced since the listing. Moving also changes
        // the path, which would break a hard-coded path reference in object content.
        if ($this->isReferenced($id)) {
            return [null, 'Asset is now referenced by an object'];
        }

        if (!$this->hasContentEvidence()) {
            return [null, 'Content-reference verification is not configured'];
        }

        $dependencyVerdict = $this->dependencyVerifier->verdict($asset);
        if ($dependencyVerdict === DependencyUsageVerdict::Referenced) {
            return [null, 'Asset is referenced by a live Pimcore element dependency'];
        }
        if ($dependencyVerdict === DependencyUsageVerdict::Unknown) {
            return [null, 'Dependency projection is not ready or contains dirty sources'];
        }

        if ($this->isReferencedInContent($asset)) {
            return [null, 'Asset is referenced in object content (text/WYSIWYG)'];
        }


        if ($verb === 'delete' && !$this->hasDeletionConfidence($id)) {
            return [null, 'Asset does not meet the definitely-unused confidence threshold'];
        }

        return [$asset, null];
    }

    /**
     * Read-only preview of the delete/move guard for one asset id: returns the skip reason, or null
     * when the asset would actually be acted on. Lets the --by-ids dry run report the real per-asset
     * outcome (not found / locked / referenced / denied) instead of optimistically echoing every id.
     */
    public function previewMutation(int $id, string $action = 'delete'): ?string
    {
        [$aclPermission, $verb] = $action === 'move' ? ['publish', 'move'] : ['delete', 'delete'];

        return $this->guardMutation($id, $aclPermission, $verb)[1];
    }

    public function isReferenced(int $assetId): bool
    {
        $count = (int) $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(PimcoreSchema::TABLE_DEPENDENCIES)
            ->where('targetid = :id')
            ->andWhere('targettype = :type')
            ->setParameter('id', $assetId)
            ->setParameter('type', PimcoreSchema::ELEMENT_TYPE_ASSET)
            ->executeQuery()
            ->fetchOne();

        return $count > 0;
    }

    // Reject loudly: with no size column, post-filtering would under-count the delete path (data loss).
    private function rejectUnsupportedSizeFilters(array $filters): void
    {
        if (isset($filters['minSize']) || isset($filters['maxSize'])) {
            throw new \InvalidArgumentException(
                'Asset Pilot: size filtering (minSize/maxSize) is not supported because the Pimcore '
                . 'assets table has no size column. Filter by type, extension, folder, or date instead.',
            );
        }
    }

    /** @param array<string, mixed> $filters */
    private function validateConfidenceFilter(array $filters): void
    {
        $confidence = (string) ($filters['confidence'] ?? '');
        if ($confidence !== '' && ConfidenceLevel::tryFrom($confidence) === null) {
            throw new \InvalidArgumentException('Invalid confidence filter.');
        }
    }

    private function hasContentEvidence(): bool
    {
        return $this->contentScanner->canVerify();
    }

    protected function hasDeletionConfidence(int $assetId): bool
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(PimcoreSchema::TABLE_ASSETS, 'a')
            ->where('a.id = :asset_id')
            ->setParameter('asset_id', $assetId);
        $this->applyUnusedPredicate($qb);
        $this->applyConfidenceFilter($qb, ConfidenceLevel::DefinitelyUnused);

        return (int) $qb->executeQuery()->fetchOne() === 1;
    }

    protected function assetAtPath(string $path): ?Asset
    {
        return Asset::getByPath($path);
    }

    protected function applyFilters($qb, array $filters): void
    {
        if (!empty($filters['type'])) {
            $types = is_array($filters['type']) ? $filters['type'] : explode(',', $filters['type']);
            $qb->andWhere($qb->expr()->in('a.type', ':types'))
                ->setParameter('types', $types, ArrayParameterType::STRING);
        }

        if (!empty($filters['extension'])) {
            $extensions = is_array($filters['extension']) ? $filters['extension'] : explode(',', $filters['extension']);
            $conditions = [];
            foreach ($extensions as $i => $ext) {
                $param = 'ext_' . $i;
                $conditions[] = 'a.filename LIKE :' . $param . Like::CLAUSE;
                $qb->setParameter($param, '%.' . Like::escape(ltrim(trim($ext), '.')));
            }
            $qb->andWhere('(' . implode(' OR ', $conditions) . ')');
        }

        if (!empty($filters['before'])) {
            $timestamp = strtotime($filters['before']);
            if ($timestamp === false) {
                throw new \InvalidArgumentException('Invalid before date filter.');
            }
            $qb->andWhere('a.modificationDate < :before')
                ->setParameter('before', $timestamp);
        }

        if (!empty($filters['after'])) {
            $timestamp = strtotime($filters['after']);
            if ($timestamp === false) {
                throw new \InvalidArgumentException('Invalid after date filter.');
            }
            $qb->andWhere('a.modificationDate > :after')
                ->setParameter('after', $timestamp);
        }

        if (!empty($filters['folder'])) {
            $qb->andWhere('a.path LIKE :folder' . Like::CLAUSE)
                ->setParameter('folder', Like::escape(rtrim($filters['folder'], '/')) . '/%');
        }

        $confidenceValue = (string) ($filters['confidence'] ?? '');
        $confidence = ConfidenceLevel::tryFrom($confidenceValue);
        if ($confidenceValue !== '' && $confidence === null) {
            throw new \InvalidArgumentException('Invalid confidence filter.');
        }
        if ($confidence !== null) {
            $this->applyConfidenceFilter($qb, $confidence);
        }
    }

    private function applyConfidenceFilter(QueryBuilder $qb, ConfidenceLevel $level): void
    {
        $spec = ConfidenceFilter::build(
            $level,
            $this->lockProperty,
            Installer::TABLE_AUDIT_LOG,
            time(),
            $this->scorer->getRecentlyUploadedDays(),
            $this->scorer->getProbablyUnusedDays(),
        );

        foreach ($spec['conditions'] as $condition) {
            $qb->andWhere($condition);
        }
        foreach ($spec['params'] as $key => $value) {
            $qb->setParameter($key, $value);
        }
    }

}
