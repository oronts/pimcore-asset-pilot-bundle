<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Audit\AuditLoggerInterface;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\RevertFailure;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Exception\RevertException;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\OperationReverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(OperationReverter::class)]
class OperationReverterTest extends TestCase
{
    #[Test]
    public function throwsWhenTheAuditEntryDoesNotExist(): void
    {
        $reason = $this->revertFailure($this->reverter(null, null), 99);

        self::assertSame(RevertFailure::AuditEntryNotFound, $reason);
    }

    #[Test]
    public function throwsWhenTheOperationIsNotCompleted(): void
    {
        $reason = $this->revertFailure($this->reverter(['status' => OperationStatus::Failed->value], null), 1);

        self::assertSame(RevertFailure::NotCompleted, $reason);
    }

    #[Test]
    public function throwsWhenTheAssetIsGone(): void
    {
        $reason = $this->revertFailure($this->reverter($this->completedEntry(), null), 1);

        self::assertSame(RevertFailure::AssetNotFound, $reason);
    }

    #[Test]
    public function throwsAPathConflictWithBothPathsWhenTheAssetMovedSince(): void
    {
        $reverter = $this->reverter($this->completedEntry(), $this->asset('/somewhere/else.png', allowed: true));

        try {
            $reverter->revertById(1);
            self::fail('expected RevertException');
        } catch (RevertException $e) {
            self::assertSame(RevertFailure::PathConflict, $e->reason);
            self::assertSame('/somewhere/else.png', $e->context['currentPath']);
            self::assertSame('/organized/a.png', $e->context['expectedPath']);
        }
    }

    #[Test]
    public function throwsPermissionDeniedWhenTheWorkspaceAclRejects(): void
    {
        $reason = $this->revertFailure(
            $this->reverter($this->completedEntry(), $this->asset('/organized/a.png', allowed: false)),
            1,
        );

        self::assertSame(RevertFailure::PermissionDenied, $reason);
    }

    #[Test]
    public function revertsACompletedMoveLogsItAndFiresTheEvent(): void
    {
        $asset = $this->asset('/organized/a.png', allowed: true);
        $asset->expects(self::once())->method('setFilename')->with('a.png');

        $logged = [];
        $dispatcher = new EventDispatcher();
        $reverted = [];
        $dispatcher->addListener(AssetPilotEvents::REVERTED, static function () use (&$reverted): void {
            $reverted[] = true;
        });

        $reverter = $this->reverter($this->completedEntry(), $asset, $dispatcher, $logged);
        $result = $reverter->revertById(1);

        self::assertSame('/organized/a.png', $result->fromPath);
        self::assertSame('/source/a.png', $result->toPath);
        self::assertCount(1, $logged);
        self::assertSame('revert:test_rule', $logged[0]->ruleName);
        self::assertSame(OperationStatus::Completed, $logged[0]->status);
        self::assertSame(42, $logged[0]->userId);
        self::assertSame([true], $reverted);
    }

    /**
     * The revert save re-triggers pimcore.asset.postUpdate -> AssetUploadListener -> organize, which
     * would move the asset straight back. The guard must mark processing before the save, mark
     * recently-moved before releasing the processing guard (so the listener is never unguarded), and
     * always release the processing guard. (P0-3, P2-21 — preserved through the controller->service move.)
     */
    #[Test]
    public function saveRevertedGuardsTheSaveInTheCorrectOrder(): void
    {
        $calls = [];
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('markAssetProcessing')->willReturnCallback(function (int $id) use (&$calls): void {
            $calls[] = "markProcessing:$id";
        });
        $loopGuard->method('markAssetRecentlyMoved')->willReturnCallback(function (int $id) use (&$calls): void {
            $calls[] = "markRecentlyMoved:$id";
        });
        $loopGuard->method('unmarkAssetProcessing')->willReturnCallback(function (int $id) use (&$calls): void {
            $calls[] = "unmarkProcessing:$id";
        });

        $asset = $this->createMock(Asset::class);
        $asset->expects(self::once())->method('save')->willReturnCallback(function () use (&$calls, &$asset): Asset {
            $calls[] = 'save';

            return $asset;
        });

        $reverter = new class ($this->createMock(AuditLoggerInterface::class), $loopGuard, new EventDispatcher(), new NullLogger()) extends OperationReverter {
            public function exposeSaveReverted(Asset $asset, int $assetId): void
            {
                $this->saveReverted($asset, $assetId);
            }
        };
        $reverter->exposeSaveReverted($asset, 42);

        self::assertSame(['markProcessing:42', 'save', 'markRecentlyMoved:42', 'unmarkProcessing:42'], $calls);
    }

    private function revertFailure(OperationReverter $reverter, int $id): RevertFailure
    {
        try {
            $reverter->revertById($id);
            self::fail('expected RevertException');
        } catch (RevertException $e) {
            return $e->reason;
        }
    }

    /** @return array<string, mixed> */
    private function completedEntry(): array
    {
        return [
            'status' => OperationStatus::Completed->value,
            'asset_id' => 7,
            'asset_path_to' => '/organized/a.png',
            'asset_path_from' => '/source/a.png',
            'object_id' => 3,
            'object_class' => 'Product',
            'rule_name' => 'test_rule',
        ];
    }

    private function asset(string $currentPath, bool $allowed): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getRealFullPath')->willReturn($currentPath);
        $asset->method('isAllowed')->willReturn($allowed);

        return $asset;
    }

    /**
     * @param array<string, mixed>|null  $entry
     * @param list<MoveOperation>|null    $loggedRef captures the logged revert operations by reference
     */
    private function reverter(?array $entry, ?Asset $asset, ?EventDispatcher $dispatcher = null, ?array &$loggedRef = null): OperationReverter
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->method('findById')->willReturn($entry);
        $auditLogger->method('log')->willReturnCallback(static function (MoveOperation $op) use (&$loggedRef): void {
            if ($loggedRef !== null) {
                $loggedRef[] = $op;
            }
        });

        $folder = $this->createMock(Asset\Folder::class);

        return new class ($auditLogger, $this->createMock(LoopGuard::class), $dispatcher ?? new EventDispatcher(), new NullLogger(), $asset, $folder) extends OperationReverter {
            public function __construct(
                AuditLoggerInterface $auditLogger,
                LoopGuard $loopGuard,
                EventDispatcher $dispatcher,
                NullLogger $logger,
                private readonly ?Asset $stubAsset,
                private readonly Asset\Folder $stubFolder,
            ) {
                parent::__construct($auditLogger, $loopGuard, $dispatcher, $logger);
            }

            protected function loadAsset(int $id): ?Asset
            {
                return $this->stubAsset;
            }

            protected function nearestExistingFolder(string $path): ?Asset\Folder
            {
                return null;
            }

            protected function createFolder(string $path): Asset\Folder
            {
                return $this->stubFolder;
            }

            protected function saveReverted(Asset $asset, int $assetId): void {}

            protected function currentUserId(): ?int
            {
                return 42;
            }
        };
    }
}
