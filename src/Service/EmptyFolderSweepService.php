<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\Query\AssetWorkspaceQueryScope;
use Oronts\AssetPilotBundle\Service\Query\Like;
use Oronts\AssetPilotBundle\Service\Query\PimcoreSchema;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;

/**
 * Finds and (guarded) deletes empty asset folders — the residue left behind after a cleanup or
 * quarantine pass. "Empty" means a folder with no direct children, found via a paged NOT EXISTS
 * query (no full-tree walk, never blocks). Deleting re-verifies the folder is still childless and
 * permission-checks it, so a folder that gained content since the listing is skipped rather than
 * recursively deleted. One pass removes the current leaf-empty folders; a parent that only held
 * those is swept on the next run.
 */
class EmptyFolderSweepService
{
    /** The asset tree root (id 1, path '/') is never a sweep candidate. */
    private const int ROOT_ID = 1;
    private const int PLAN_VERSION = 1;

    public function __construct(
        protected readonly Connection $connection,
        protected readonly LoggerInterface $logger,
        protected readonly ElementAuthorization $authorization,
        protected readonly LoopGuard $loopGuard,
        protected readonly AssetWorkspaceQueryScope $workspaceScope,
    ) {}

    /**
     * @return array{items: list<array{id: int, path: string}>, page: int, limit: int, hasMore: bool}
     */
    public function findEmpty(?string $root = null, int $page = 1, int $limit = 100): array
    {
        $page = max(1, $page);
        $limit = max(1, $limit);

        $items = [];
        foreach ($this->listEmptyFolderRows($root, ($page - 1) * $limit, $limit + 1) as $row) {
            $folder = $this->loadFolder((int) $row['id']);
            if ($folder === null || !$this->authorization->isAllowed($folder, 'view')) {
                continue;
            }
            $items[] = ['id' => (int) $row['id'], 'path' => (string) $row['full_path']];
        }

        $hasMore = count($items) > $limit;

        return ['items' => array_slice($items, 0, $limit), 'page' => $page, 'limit' => $limit, 'hasMore' => $hasMore];
    }

    /** @param list<int> $folderIds */
    public function createDeletePlan(array $folderIds): ApplyPlan
    {
        sort($folderIds, SORT_NUMERIC);

        return new ApplyPlan(
            kind: 'empty-folder-delete',
            actor: $this->authorization->currentActor(),
            request: ['folderIds' => $folderIds],
            config: ['version' => self::PLAN_VERSION],
            targets: array_map(
                fn (int $folderId): ApplyPlanTarget => new ApplyPlanTarget('folder:' . $folderId, $this->fingerprint($folderId)),
                $folderIds,
            ),
        );
    }

    /**
     * @param list<int> $folderIds
     * @return array{deleted: 0, eligible: int, skipped: int, failed: int, errors: array<int, string>}
     */
    public function previewDelete(array $folderIds): array
    {
        $eligible = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($folderIds as $id) {
            if ($id <= self::ROOT_ID) {
                ++$skipped;
                continue;
            }

            $folder = $this->loadFolder($id);
            if (!$folder instanceof Asset\Folder || $folder->hasChildren()) {
                ++$skipped;
                continue;
            }
            if (!$this->authorization->isAllowed($folder, 'delete')) {
                $errors[$id] = 'Not permitted to delete this folder';
                ++$failed;
                continue;
            }

            ++$eligible;
        }

        return ['deleted' => 0, 'eligible' => $eligible, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * @param list<int> $folderIds
     * @param array<string, string> $expectedFingerprints
     * @return array{deleted: int, skipped: int, failed: int, errors: array<int, string>}
     */
    public function deleteEmpty(array $folderIds, array $expectedFingerprints): array
    {
        sort($folderIds, SORT_NUMERIC);
        $lockedIds = [];

        try {
            foreach ($folderIds as $id) {
                if (!$this->loopGuard->acquireAsset($id)) {
                    throw new StaleApplyPlanException(sprintf('Folder %d is busy. Preview the operation again.', $id));
                }
                $lockedIds[] = $id;
            }

            foreach ($folderIds as $id) {
                $this->assertUnchanged($id, $expectedFingerprints);
                $this->loopGuard->refreshAsset($id);
            }

            return $this->deleteValidatedFolders($folderIds);
        } finally {
            foreach (array_reverse($lockedIds) as $id) {
                $this->loopGuard->releaseAsset($id);
            }
        }
    }

    /**
     * @param list<int> $folderIds
     * @return array{deleted: int, skipped: int, failed: int, errors: array<int, string>}
     */
    private function deleteValidatedFolders(array $folderIds): array
    {
        $deleted = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($folderIds as $id) {
            $id = (int) $id;
            if ($id <= self::ROOT_ID) {
                ++$skipped;
                continue;
            }

            try {
                $folder = $this->loadFolder($id);
                if ($folder === null) {
                    ++$skipped;
                    continue;
                }

                if ($folder->hasChildren()) {
                    ++$skipped;
                    continue;
                }

                if (!$this->authorization->isAllowed($folder, 'delete')) {
                    $errors[$id] = 'Not permitted to delete this folder';
                    ++$failed;
                    continue;
                }

                if (!$this->deleteFolderIfStillEmpty($folder)) {
                    ++$skipped;
                    continue;
                }
                ++$deleted;
                $this->logger->info('Asset Pilot: swept empty folder {id} at {path}', [
                    'id' => $id,
                    'path' => $folder->getRealFullPath(),
                ]);
            } catch (\Throwable $e) {
                $errors[$id] = 'Failed to delete the empty folder.';
                ++$failed;
                $this->logger->error('Asset Pilot: failed to delete empty folder {id}.', ['id' => $id, 'exception' => $e]);
            }
        }

        return ['deleted' => $deleted, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
    }

    /** @param array<string, string> $expectedFingerprints */
    private function assertUnchanged(int $folderId, array $expectedFingerprints): void
    {
        $key = 'folder:' . $folderId;
        if (!isset($expectedFingerprints[$key]) || !hash_equals($expectedFingerprints[$key], $this->fingerprint($folderId))) {
            throw new StaleApplyPlanException(sprintf(
                'Folder %d changed after preview. Preview the operation again before applying.',
                $folderId,
            ));
        }
    }

    private function fingerprint(int $folderId): string
    {
        $folder = $this->loadFolder($folderId);
        $state = $folder instanceof Asset\Folder
            ? [
                'exists' => true,
                'hasChildren' => $folder->hasChildren(),
                'modifiedAt' => $folder->getModificationDate(),
                'path' => $folder->getRealFullPath(),
            ]
            : ['exists' => false];

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return list<array{id: int|string, full_path: string}>
     */
    protected function listEmptyFolderRows(?string $root, int $offset, int $limit): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('a.id', 'CONCAT(a.path, a.filename) AS full_path')
            ->from(PimcoreSchema::TABLE_ASSETS, 'a')
            ->where('a.type = :folder')
            ->andWhere('a.id > :root')
            ->andWhere(sprintf(
                'NOT EXISTS (SELECT 1 FROM %s c WHERE c.parentId = a.id)',
                PimcoreSchema::TABLE_ASSETS,
            ))
            ->setParameter('folder', PimcoreSchema::ASSET_TYPE_FOLDER)
            ->setParameter('root', self::ROOT_ID)
            ->orderBy('full_path', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        if ($root !== null && $root !== '' && $root !== '/') {
            $qb->andWhere('a.path LIKE :path')->setParameter('path', Like::escape(rtrim($root, '/') . '/') . '%');
        }
        $this->workspaceScope->applyView($qb, 'a', 'emptyFolderList');

        return $qb->executeQuery()->fetchAllAssociative();
    }

    protected function loadFolder(int $id): ?Asset\Folder
    {
        $folder = Asset::getById($id);

        return $folder instanceof Asset\Folder ? $folder : null;
    }

    protected function deleteFolder(Asset\Folder $folder): void
    {
        $folder->delete();
    }

    protected function deleteFolderIfStillEmpty(Asset\Folder $folder): bool
    {
        return $this->connection->transactional(function () use ($folder): bool {
            $query = $this->connection->createQueryBuilder()
                ->select('id')
                ->from(PimcoreSchema::TABLE_ASSETS)
                ->where('parentId = :parentId')
                ->setParameter('parentId', $folder->getId())
                ->setMaxResults(1);
            if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
                $query->forUpdate();
            }
            $childId = $query->executeQuery()->fetchOne();
            if ($childId !== false) {
                return false;
            }

            $this->deleteFolder($folder);

            return true;
        });
    }
}
