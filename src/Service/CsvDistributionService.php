<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Audit\AuditWriterInterface;
use Oronts\AssetPilotBundle\Enum\CsvDistributionOutcome;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\CsvDistributionReport;
use Oronts\AssetPilotBundle\Model\CsvDistributionResult;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;

class CsvDistributionService implements CsvDistributionServiceInterface
{
    /** @param list<string> $excludeFolders */
    public function __construct(
        protected readonly LoopGuardedAssetSaver $assetSaver,
        protected readonly AuditWriterInterface $auditLog,
        protected readonly LoggerInterface $logger,
        protected readonly string $lockProperty = AssetProtection::DEFAULT_LOCK_PROPERTY,
        protected readonly array $excludeFolders = [],
    ) {}

    public function distribute(string $csvPath, string $assetColumn, string $targetColumn, bool $dryRun): CsvDistributionReport
    {
        $results = [];
        foreach ($this->readRows($csvPath, $assetColumn, $targetColumn) as [$rowNumber, $assetReference, $targetReference]) {
            $results[] = $this->processRow($rowNumber, $assetReference, $targetReference, $dryRun);
        }

        return new CsvDistributionReport($results, $dryRun);
    }

    protected function processRow(int $rowNumber, string $assetReference, string $targetReference, bool $dryRun): CsvDistributionResult
    {
        if ($assetReference === '' || $targetReference === '') {
            return new CsvDistributionResult($rowNumber, $assetReference, $targetReference, CsvDistributionOutcome::Invalid, message: 'both an asset and a target column value are required');
        }

        $asset = $this->resolveAsset($assetReference);
        if (!$asset instanceof Asset || $asset instanceof Asset\Folder) {
            return new CsvDistributionResult($rowNumber, $assetReference, $targetReference, CsvDistributionOutcome::AssetNotFound, message: 'no asset matched this reference');
        }

        $folder = $this->resolveFolder($targetReference);
        if (!$folder instanceof Asset\Folder) {
            return new CsvDistributionResult($rowNumber, $assetReference, $targetReference, CsvDistributionOutcome::TargetNotFound, (int) $asset->getId(), message: 'the target folder does not exist');
        }

        $assetId = (int) $asset->getId();
        $fromPath = (string) $asset->getRealFullPath();
        $targetFolderPath = rtrim((string) $folder->getRealFullPath(), '/');
        $toPath = $targetFolderPath . '/' . $asset->getFilename();

        // Honor the same lock and excluded-folder protections every other destructive move path enforces,
        // in both preview and apply so the preview never promises a move the apply refuses.
        if ($this->assetIsLocked($asset)) {
            return new CsvDistributionResult($rowNumber, $assetReference, $targetReference, CsvDistributionOutcome::Skipped, $assetId, $fromPath, $toPath, 'locked against automated moves');
        }
        if ($this->isInExcludedFolder($fromPath) || $this->isInExcludedFolder($toPath)) {
            return new CsvDistributionResult($rowNumber, $assetReference, $targetReference, CsvDistributionOutcome::Skipped, $assetId, $fromPath, $toPath, 'source or target is a protected excluded folder');
        }

        if ($fromPath === $toPath) {
            return new CsvDistributionResult($rowNumber, $assetReference, $targetReference, CsvDistributionOutcome::Skipped, $assetId, $fromPath, $toPath, 'already in the target folder');
        }

        if ($dryRun) {
            return new CsvDistributionResult($rowNumber, $assetReference, $targetReference, CsvDistributionOutcome::Planned, $assetId, $fromPath, $toPath);
        }

        try {
            $this->move($asset, $folder);
        } catch (\Throwable $exception) {
            return new CsvDistributionResult($rowNumber, $assetReference, $targetReference, CsvDistributionOutcome::Invalid, $assetId, $fromPath, $toPath, $exception->getMessage());
        }

        $this->auditMove($assetId, $fromPath, $toPath);

        return new CsvDistributionResult($rowNumber, $assetReference, $targetReference, CsvDistributionOutcome::Moved, $assetId, $fromPath, $toPath);
    }

    /**
     * Stream the CSV rows so a multi-million-row mapping is not buffered in full. Throws only when the
     * file or its header is unusable; individual data rows never throw here.
     *
     * @return \Generator<int, array{0: int, 1: string, 2: string}>
     */
    protected function readRows(string $csvPath, string $assetColumn, string $targetColumn): \Generator
    {
        if (!is_file($csvPath) || !is_readable($csvPath)) {
            throw new \RuntimeException(sprintf('The CSV file "%s" does not exist or is not readable.', $csvPath));
        }

        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('The CSV file "%s" could not be opened.', $csvPath));
        }

        try {
            $header = fgetcsv($handle, escape: '');
            if (!is_array($header)) {
                throw new \RuntimeException('The CSV file is empty; a header row naming the asset and target columns is required.');
            }
            $header = array_map(static fn (mixed $value): string => trim((string) $value), $header);
            if (isset($header[0])) {
                // Strip a UTF-8 BOM so an Excel "CSV UTF-8" export still resolves its first column.
                $header[0] = ltrim($header[0], "\u{FEFF}");
            }
            $assetIndex = array_search($assetColumn, $header, true);
            $targetIndex = array_search($targetColumn, $header, true);
            if ($assetIndex === false || $targetIndex === false) {
                throw new \RuntimeException(sprintf('The CSV header must contain the "%s" and "%s" columns.', $assetColumn, $targetColumn));
            }

            $rowNumber = 1;
            while (($record = fgetcsv($handle, escape: '')) !== false) {
                ++$rowNumber;
                if ($record === [null]) {
                    continue;
                }
                yield [
                    $rowNumber,
                    trim((string) ($record[$assetIndex] ?? '')),
                    trim((string) ($record[$targetIndex] ?? '')),
                ];
            }
        } finally {
            fclose($handle);
        }
    }

    /** A numeric reference resolves by id, anything else by path. */
    protected function resolveAsset(string $reference): ?Asset
    {
        if (ctype_digit($reference)) {
            return Asset::getById((int) $reference);
        }

        return Asset::getByPath($reference);
    }

    protected function resolveFolder(string $reference): ?Asset\Folder
    {
        $folder = ctype_digit($reference) ? Asset::getById((int) $reference) : Asset::getByPath($reference);

        return $folder instanceof Asset\Folder ? $folder : null;
    }

    protected function move(Asset $asset, Asset\Folder $folder): void
    {
        $targetPath = (string) $folder->getRealFullPath();
        $this->assetSaver->save(
            $asset,
            static function (Asset $mutable) use ($folder): void {
                $mutable->setParent($folder);
            },
            ['versionNote' => 'Asset Pilot: distributed to ' . $targetPath . ' from CSV mapping'],
        );
    }

    /** Best-effort: a durable move must not be rolled back or the run aborted because the audit write failed. */
    protected function auditMove(int $assetId, string $fromPath, string $toPath): void
    {
        try {
            $this->auditLog->log(new MoveOperation(
                $assetId,
                $fromPath,
                $toPath,
                0,
                '',
                'csv_distribution',
                OperationStatus::Completed,
                TriggerType::Manual,
            ));
        } catch (\Throwable $exception) {
            $this->logger->error('Asset Pilot: moved asset {id} from CSV but could not write its audit record: {error}', [
                'id' => $assetId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    protected function assetIsLocked(Asset $asset): bool
    {
        return AssetProtection::isLocked($asset, $this->lockProperty);
    }

    protected function isInExcludedFolder(string $path): bool
    {
        foreach ($this->excludeFolders as $excluded) {
            $excluded = rtrim($excluded, '/');
            if ($excluded !== '' && ($path === $excluded || str_starts_with($path, $excluded . '/'))) {
                return true;
            }
        }

        return false;
    }
}
