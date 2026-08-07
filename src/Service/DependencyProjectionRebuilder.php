<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Enum\DependencyProjectionState;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Model\DependencyProjectionBatchResult;
use Oronts\AssetPilotBundle\Model\DependencySourceToken;
use Oronts\AssetPilotBundle\Service\Query\PimcoreSchema;
use Pimcore\Model\Element\AbstractElement;
use Pimcore\Model\Element\Service as ElementService;
use Psr\Log\LoggerInterface;

class DependencyProjectionRebuilder implements DependencyProjectionRebuilderInterface
{
    private const array SOURCE_TABLES = [
        PimcoreSchema::ELEMENT_TYPE_OBJECT => PimcoreSchema::TABLE_OBJECTS,
        PimcoreSchema::ELEMENT_TYPE_DOCUMENT => PimcoreSchema::TABLE_DOCUMENTS,
        PimcoreSchema::ELEMENT_TYPE_ASSET => PimcoreSchema::TABLE_ASSETS,
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly DependencyProjectionInterface $projection,
        private readonly DependencyProjectionFreshnessInterface $freshness,
        private readonly LoggerInterface $logger,
    ) {}

    public function rebuildBatch(int $limit, bool $restart = false): DependencyProjectionBatchResult
    {
        if ($limit < 1 || $limit > 10000) {
            throw new \InvalidArgumentException('The dependency projection batch size must be between 1 and 10000.');
        }

        try {
            $status = $this->freshness->beginRebuild($restart);
            if ($status->state === DependencyProjectionState::Ready) {
                return new DependencyProjectionBatchResult(0, 0, true, $status);
            }
            if ($status->state !== DependencyProjectionState::Building) {
                throw new \RuntimeException('The dependency projection did not enter the building state.');
            }

            $result = $status->cursorType === 'complete'
                ? $this->repairDirtySources($limit)
                : $this->scanSources($status->cursorType ?? PimcoreSchema::ELEMENT_TYPE_OBJECT, $status->cursorId, $limit);

            $status = $result['atEnd'] && $result['failed'] === 0
                ? $this->freshness->completeRebuild()
                : $this->freshness->status();

            return new DependencyProjectionBatchResult(
                $result['processed'],
                $result['failed'],
                $status->state === DependencyProjectionState::Ready,
                $status,
            );
        } catch (\Throwable $e) {
            try {
                $this->freshness->failRebuild($e->getMessage());
            } catch (\Throwable $recordingError) {
                $this->logger->critical('Asset Pilot: dependency projection failure state could not be recorded.', [
                    'exception' => $recordingError,
                    'rebuild_exception' => $e,
                ]);
            }
            throw $e;
        }
    }

    /** @return array{processed: int, failed: int, atEnd: bool} */
    private function scanSources(string $sourceType, int $afterId, int $limit): array
    {
        $processed = 0;
        $failed = 0;

        while ($processed < $limit) {
            $remaining = $limit - $processed;
            $ids = $this->sourceIds($sourceType, $afterId, $remaining);
            foreach ($ids as $sourceId) {
                ++$processed;
                $afterId = $sourceId;
                if (!$this->refreshSource($sourceType, $sourceId)) {
                    ++$failed;
                }
                $this->freshness->advanceRebuild($sourceType, $sourceId);
            }

            if (count($ids) === $remaining) {
                return ['processed' => $processed, 'failed' => $failed, 'atEnd' => false];
            }

            $nextType = $this->nextType($sourceType);
            if ($nextType === null) {
                $this->freshness->advanceRebuild('complete', 0);

                return ['processed' => $processed, 'failed' => $failed, 'atEnd' => true];
            }

            $sourceType = $nextType;
            $afterId = 0;
            $this->freshness->advanceRebuild($sourceType, 0);
        }

        return ['processed' => $processed, 'failed' => $failed, 'atEnd' => false];
    }

    /** @return array{processed: int, failed: int, atEnd: bool} */
    private function repairDirtySources(int $limit): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('source_key', 'source_type', 'source_id', 'revision')
            ->from(Installer::TABLE_DEPENDENCY_SOURCE)
            ->where('state = :state')
            ->andWhere('source_id IS NOT NULL')
            ->orderBy('dirty_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setParameter('state', 'dirty')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $failed = 0;
        foreach ($rows as $row) {
            $sourceType = (string) $row['source_type'];
            $sourceId = (int) $row['source_id'];
            $source = $this->loadSource($sourceType, $sourceId);
            $token = new DependencySourceToken((string) $row['source_key'], (int) $row['revision']);
            if ($source === null) {
                $this->projection->remove($sourceType, $sourceId, $token);

                continue;
            }
            if (!$this->refreshToken($source, $token)) {
                ++$failed;
            }
        }

        $status = $this->freshness->status();

        return [
            'processed' => count($rows),
            'failed' => $failed,
            'atEnd' => count($rows) < $limit && $status->dirtySources === 0,
        ];
    }

    private function refreshSource(string $sourceType, int $sourceId): bool
    {
        $token = $this->projection->markDirty($sourceType, $sourceId);
        $source = $this->loadSource($sourceType, $sourceId);
        if ($source === null) {
            $this->logger->warning('Asset Pilot: dependency projection source {type}:{id} could not be loaded.', [
                'type' => $sourceType,
                'id' => $sourceId,
            ]);

            return false;
        }

        return $this->refreshToken($source, $token);
    }

    private function refreshToken(AbstractElement $source, DependencySourceToken $token): bool
    {
        try {
            return $this->projection->refresh($source, $token);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: dependency projection source refresh failed.', [
                'source_key' => $token->sourceKey,
                'exception' => $e,
            ]);

            return false;
        }
    }

    /** @return list<int> */
    protected function sourceIds(string $sourceType, int $afterId, int $limit): array
    {
        $table = self::SOURCE_TABLES[$sourceType] ?? throw new \InvalidArgumentException(sprintf('Unsupported dependency source type "%s".', $sourceType));

        return array_map(
            'intval',
            $this->connection->createQueryBuilder()
                ->select('id')
                ->from($table)
                ->where('id > :afterId')
                ->orderBy('id', 'ASC')
                ->setParameter('afterId', $afterId)
                ->setMaxResults($limit)
                ->executeQuery()
                ->fetchFirstColumn(),
        );
    }

    protected function loadSource(string $sourceType, int $sourceId): ?AbstractElement
    {
        return ElementService::getElementById($sourceType, $sourceId, ['force' => true]);
    }

    private function nextType(string $sourceType): ?string
    {
        return match ($sourceType) {
            PimcoreSchema::ELEMENT_TYPE_OBJECT => PimcoreSchema::ELEMENT_TYPE_DOCUMENT,
            PimcoreSchema::ELEMENT_TYPE_DOCUMENT => PimcoreSchema::ELEMENT_TYPE_ASSET,
            PimcoreSchema::ELEMENT_TYPE_ASSET => null,
            default => throw new \InvalidArgumentException(sprintf('Unsupported dependency rebuild cursor "%s".', $sourceType)),
        };
    }
}
