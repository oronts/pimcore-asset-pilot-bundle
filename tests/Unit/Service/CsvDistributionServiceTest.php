<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Audit\AuditWriterInterface;
use Oronts\AssetPilotBundle\Enum\CsvDistributionOutcome;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Service\AssetProtection;
use Oronts\AssetPilotBundle\Service\CsvDistributionService;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\LoopGuardedAssetSaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

#[CoversClass(CsvDistributionService::class)]
final class CsvDistributionServiceTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    #[Test]
    public function previewsPlannedMovesWithoutTouchingTheSaver(): void
    {
        $csv = $this->csv("asset,target\n12,/Photos/2026\n");
        $moved = [];
        $report = $this->service(
            resolveAsset: fn (string $ref): ?Asset => $this->image(12, '/Uploads/a.jpg', '/Uploads'),
            resolveFolder: fn (string $ref): ?Asset\Folder => $this->folder('/Photos/2026'),
            onMove: static function () use (&$moved): void {
                $moved[] = true;
            },
        )->distribute($csv, 'asset', 'target', dryRun: true);

        self::assertTrue($report->dryRun);
        self::assertSame(1, $report->countOf(CsvDistributionOutcome::Planned));
        self::assertSame('/Uploads/a.jpg', $report->results[0]->fromPath);
        self::assertSame('/Photos/2026/a.jpg', $report->results[0]->toPath);
        self::assertSame([], $moved, 'a preview never moves an asset');
    }

    #[Test]
    public function movesAndAuditsOnApply(): void
    {
        $csv = $this->csv("asset,target\n12,/Photos\n");
        $moved = [];
        $audit = $this->createMock(AuditWriterInterface::class);
        $audit->expects(self::once())->method('log')->with(self::callback(
            static fn (MoveOperation $op): bool => $op->assetId === 12 && $op->ruleName === 'csv_distribution' && $op->targetPath === '/Photos/a.jpg',
        ));

        $report = $this->service(
            resolveAsset: fn (string $ref): ?Asset => $this->image(12, '/Uploads/a.jpg', '/Uploads'),
            resolveFolder: fn (string $ref): ?Asset\Folder => $this->folder('/Photos'),
            onMove: static function () use (&$moved): void {
                $moved[] = true;
            },
            audit: $audit,
        )->distribute($csv, 'asset', 'target', dryRun: false);

        self::assertSame(1, $report->countOf(CsvDistributionOutcome::Moved));
        self::assertSame([true], $moved);
    }

    #[Test]
    public function reportsUnresolvedAssetsAndTargetsWithoutMoving(): void
    {
        $csv = $this->csv("asset,target\n999,/Photos\n12,/Nope\n,\n");
        $report = $this->service(
            resolveAsset: fn (string $ref): ?Asset => $ref === '12' ? $this->image(12, '/Uploads/a.jpg', '/Uploads') : null,
            resolveFolder: fn (string $ref): ?Asset\Folder => $ref === '/Photos' ? $this->folder('/Photos') : null,
        )->distribute($csv, 'asset', 'target', dryRun: true);

        self::assertSame(CsvDistributionOutcome::AssetNotFound, $report->results[0]->outcome);
        self::assertSame(CsvDistributionOutcome::TargetNotFound, $report->results[1]->outcome);
        self::assertSame(CsvDistributionOutcome::Invalid, $report->results[2]->outcome, 'a blank row is invalid, not fatal');
        self::assertSame(3, $report->problemCount());
    }

    #[Test]
    public function skipsAnAssetAlreadyInTheTargetFolder(): void
    {
        $csv = $this->csv("asset,target\n12,/Photos\n");
        $report = $this->service(
            resolveAsset: fn (string $ref): ?Asset => $this->image(12, '/Photos/a.jpg', '/Photos'),
            resolveFolder: fn (string $ref): ?Asset\Folder => $this->folder('/Photos'),
        )->distribute($csv, 'asset', 'target', dryRun: false);

        self::assertSame(1, $report->countOf(CsvDistributionOutcome::Skipped));
    }

    #[Test]
    public function capturesAMoveFailureAsAnInvalidRowRatherThanAborting(): void
    {
        $csv = $this->csv("asset,target\n12,/Photos\n13,/Photos\n");
        $report = $this->service(
            resolveAsset: fn (string $ref): ?Asset => $this->image((int) $ref, '/Uploads/' . $ref . '.jpg', '/Uploads'),
            resolveFolder: fn (string $ref): ?Asset\Folder => $this->folder('/Photos'),
            onMove: static function (Asset $asset): void {
                if ((int) $asset->getId() === 12) {
                    throw new \RuntimeException('name collision');
                }
            },
        )->distribute($csv, 'asset', 'target', dryRun: false);

        self::assertSame(CsvDistributionOutcome::Invalid, $report->results[0]->outcome);
        self::assertStringContainsString('name collision', $report->results[0]->message);
        self::assertSame(CsvDistributionOutcome::Moved, $report->results[1]->outcome, 'a later row still runs after one fails');
    }

    #[Test]
    public function rejectsAHeaderMissingTheConfiguredColumns(): void
    {
        $csv = $this->csv("id,folder\n12,/Photos\n");

        $this->expectException(\RuntimeException::class);
        $this->service()->distribute($csv, 'asset', 'target', dryRun: true);
    }

    #[Test]
    public function rejectsAMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service()->distribute('/does/not/exist.csv', 'asset', 'target', dryRun: true);
    }

    #[Test]
    public function stripsAUtf8BomFromTheHeaderSoAnExcelExportStillResolves(): void
    {
        $csv = $this->csv("\u{FEFF}asset,target\n12,/Photos\n");
        $report = $this->service(
            resolveAsset: fn (string $ref): ?Asset => $this->image(12, '/Uploads/a.jpg', '/Uploads'),
            resolveFolder: fn (string $ref): ?Asset\Folder => $this->folder('/Photos'),
        )->distribute($csv, 'asset', 'target', dryRun: true);

        self::assertSame(1, $report->countOf(CsvDistributionOutcome::Planned));
    }

    #[Test]
    public function skipsALockedAssetInBothPreviewAndApply(): void
    {
        $csv = $this->csv("asset,target\n12,/Photos\n");
        $moved = [];
        $report = $this->service(
            resolveAsset: fn (string $ref): ?Asset => $this->image(12, '/Uploads/a.jpg', '/Uploads'),
            resolveFolder: fn (string $ref): ?Asset\Folder => $this->folder('/Photos'),
            onMove: static function () use (&$moved): void {
                $moved[] = true;
            },
            isLocked: static fn (): bool => true,
        )->distribute($csv, 'asset', 'target', dryRun: false);

        self::assertSame(1, $report->countOf(CsvDistributionOutcome::Skipped));
        self::assertStringContainsString('locked', $report->results[0]->message);
        self::assertSame([], $moved, 'a locked asset is never moved');
    }

    #[Test]
    public function skipsAnAssetInAnExcludedFolder(): void
    {
        $csv = $this->csv("asset,target\n12,/Photos\n");
        $report = $this->service(
            resolveAsset: fn (string $ref): ?Asset => $this->image(12, '/Protected/a.jpg', '/Protected'),
            resolveFolder: fn (string $ref): ?Asset\Folder => $this->folder('/Photos'),
            excludeFolders: ['/Protected'],
        )->distribute($csv, 'asset', 'target', dryRun: true);

        self::assertSame(1, $report->countOf(CsvDistributionOutcome::Skipped));
        self::assertStringContainsString('excluded', $report->results[0]->message);
    }

    #[Test]
    public function keepsTheMoveAndRunGoingWhenTheAuditWriteFails(): void
    {
        $csv = $this->csv("asset,target\n12,/Photos\n13,/Photos\n");
        $audit = $this->createMock(AuditWriterInterface::class);
        $audit->method('log')->willThrowException(new \RuntimeException('audit db down'));

        $report = $this->service(
            resolveAsset: fn (string $ref): ?Asset => $this->image((int) $ref, '/Uploads/' . $ref . '.jpg', '/Uploads'),
            resolveFolder: fn (string $ref): ?Asset\Folder => $this->folder('/Photos'),
            audit: $audit,
        )->distribute($csv, 'asset', 'target', dryRun: false);

        self::assertSame(2, $report->countOf(CsvDistributionOutcome::Moved), 'a failed audit write never aborts the run or downgrades a completed move');
    }

    #[Test]
    public function keepsTheRunGoingEvenWhenTheRecoveryLoggerAlsoThrows(): void
    {
        $csv = $this->csv("asset,target\n12,/Photos\n13,/Photos\n");
        $audit = $this->createMock(AuditWriterInterface::class);
        $audit->method('log')->willThrowException(new \RuntimeException('audit db down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willThrowException(new \RuntimeException('logger disk full'));

        $report = $this->service(
            resolveAsset: fn (string $ref): ?Asset => $this->image((int) $ref, '/Uploads/' . $ref . '.jpg', '/Uploads'),
            resolveFolder: fn (string $ref): ?Asset\Folder => $this->folder('/Photos'),
            audit: $audit,
            logger: $logger,
        )->distribute($csv, 'asset', 'target', dryRun: false);

        self::assertSame(2, $report->countOf(CsvDistributionOutcome::Moved), 'a broken logger must not defeat the best-effort audit contract or abort the run');
    }

    #[Test]
    public function honorsARealAssetProtectionLockWithoutOverridingTheSeam(): void
    {
        $csv = $this->csv("asset,target\n12,/Photos\n");
        $locked = $this->createMock(Asset\Image::class);
        $locked->method('getId')->willReturn(12);
        $locked->method('getRealFullPath')->willReturn('/Uploads/a.jpg');
        $locked->method('getFilename')->willReturn('a.jpg');
        $locked->method('hasProperty')->with(AssetProtection::DEFAULT_LOCK_PROPERTY)->willReturn(true);
        $locked->method('getProperty')->with(AssetProtection::DEFAULT_LOCK_PROPERTY)->willReturn(true);
        $moved = [];

        $report = $this->service(
            resolveAsset: fn (string $ref): ?Asset => $locked,
            resolveFolder: fn (string $ref): ?Asset\Folder => $this->folder('/Photos'),
            onMove: static function () use (&$moved): void {
                $moved[] = true;
            },
        )->distribute($csv, 'asset', 'target', dryRun: false);

        self::assertSame(1, $report->countOf(CsvDistributionOutcome::Skipped));
        self::assertStringContainsString('locked', $report->results[0]->message);
        self::assertSame([], $moved, 'the real AssetProtection wiring skips a locked asset with no seam override');
    }

    private function service(
        ?callable $resolveAsset = null,
        ?callable $resolveFolder = null,
        ?callable $onMove = null,
        ?AuditWriterInterface $audit = null,
        ?callable $isLocked = null,
        array $excludeFolders = [],
        ?LoggerInterface $logger = null,
    ): CsvDistributionService {
        $saver = new LoopGuardedAssetSaver($this->createMock(LoopGuard::class));

        return new class ($saver, $audit ?? $this->createMock(AuditWriterInterface::class), $logger ?? new NullLogger(), $excludeFolders, $resolveAsset, $resolveFolder, $onMove, $isLocked) extends CsvDistributionService {
            /** @param list<string> $excludeFolders */
            public function __construct(
                LoopGuardedAssetSaver $saver,
                AuditWriterInterface $audit,
                LoggerInterface $logger,
                array $excludeFolders,
                private readonly mixed $resolveAssetFn,
                private readonly mixed $resolveFolderFn,
                private readonly mixed $onMoveFn,
                private readonly mixed $isLockedFn,
            ) {
                parent::__construct($saver, $audit, $logger, AssetProtection::DEFAULT_LOCK_PROPERTY, $excludeFolders);
            }

            protected function resolveAsset(string $reference): ?Asset
            {
                return $this->resolveAssetFn === null ? null : ($this->resolveAssetFn)($reference);
            }

            protected function resolveFolder(string $reference): ?Asset\Folder
            {
                return $this->resolveFolderFn === null ? null : ($this->resolveFolderFn)($reference);
            }

            protected function move(Asset $asset, Asset\Folder $folder): void
            {
                if ($this->onMoveFn !== null) {
                    ($this->onMoveFn)($asset, $folder);
                }
            }

            protected function assetIsLocked(Asset $asset): bool
            {
                return $this->isLockedFn !== null ? (bool) ($this->isLockedFn)($asset) : parent::assetIsLocked($asset);
            }
        };
    }

    private function image(int $id, string $fullPath, string $parentPath): Asset\Image
    {
        $asset = $this->createMock(Asset\Image::class);
        $asset->method('getId')->willReturn($id);
        $asset->method('getRealFullPath')->willReturn($fullPath);
        $asset->method('getFilename')->willReturn(basename($fullPath));

        return $asset;
    }

    private function folder(string $fullPath): Asset\Folder
    {
        $folder = $this->createMock(Asset\Folder::class);
        $folder->method('getRealFullPath')->willReturn($fullPath);

        return $folder;
    }

    private function csv(string $content): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'ap-csv-');
        file_put_contents($file, $content);
        $this->tempFiles[] = $file;

        return $file;
    }
}
