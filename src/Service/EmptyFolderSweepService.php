<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
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

    public function __construct(
        protected readonly Connection $connection,
        protected readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{items: list<array{id: int, path: string}>, page: int, limit: int}
     */
    public function findEmpty(?string $root = null, int $page = 1, int $limit = 100): array
    {
        $page = max(1, $page);
        $limit = max(1, $limit);

        $items = [];
        foreach ($this->listEmptyFolderRows($root, ($page - 1) * $limit, $limit) as $row) {
            $items[] = ['id' => (int) $row['id'], 'path' => (string) $row['full_path']];
        }

        return ['items' => $items, 'page' => $page, 'limit' => $limit];
    }

    /**
     * @param int[] $folderIds
     * @return array{deleted: int, skipped: int, failed: int, errors: array<int, string>}
     */
    public function deleteEmpty(array $folderIds): array
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

                // Re-verify state before the destructive (recursive) delete: a folder that gained a
                // child since the listing must NOT be swept, or the sweep would delete real content.
                if ($folder->hasChildren()) {
                    ++$skipped;
                    continue;
                }

                // Per-folder Pimcore workspace ACL (isAllowed() resolves the user and returns true on CLI).
                if (!$folder->isAllowed('delete')) {
                    $errors[$id] = 'Not permitted to delete this folder';
                    ++$failed;
                    continue;
                }

                $this->deleteFolder($folder);
                ++$deleted;
                $this->logger->info('Asset Pilot: swept empty folder {id} at {path}', [
                    'id' => $id,
                    'path' => $folder->getRealFullPath(),
                ]);
            } catch (\Throwable $e) {
                $errors[$id] = $e->getMessage();
                ++$failed;
            }
        }

        return ['deleted' => $deleted, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
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
}
