<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Audit\AuditQueryInterface;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\RevertFailure;
use Oronts\AssetPilotBundle\Event\AssetMutationEvent;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Exception\RevertException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Model\OperationHandle;
use Oronts\AssetPilotBundle\Model\OperationIntent;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\LoopGuardedAssetSaver;
use Oronts\AssetPilotBundle\Service\OperationJournalInterface;
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
    public function rejectsAProtectedAsset(): void
    {
        $reason = $this->revertFailure(
            $this->reverter($this->completedEntry(), $this->asset('/organized/a.png', allowed: true, locked: true)),
            1,
        );

        self::assertSame(RevertFailure::AssetLocked, $reason);
    }


    #[Test]
    public function revertsACompletedMoveLogsItAndFiresTheEvent(): void
    {
        $asset = $this->asset('/organized/a.png', allowed: true);
        $asset->expects(self::once())->method('setFilename')->with('a.png');

        $logged = [];
        $dispatcher = new EventDispatcher();
        $reverted = [];
        $dispatcher->addListener(AssetPilotEvents::REVERTED, static function (AssetMutationEvent $event) use (&$reverted): void {
            $reverted[] = $event->context;
        });

        $reverter = $this->reverter($this->completedEntry(), $asset, $dispatcher, $logged);
        $result = $reverter->revertById(1);

        self::assertSame('/organized/a.png', $result->fromPath);
        self::assertSame('/source/a.png', $result->toPath);
        self::assertCount(1, $logged);
        self::assertSame('/organized/a.png', $logged[0]->sourcePath);
        self::assertSame('/source/a.png', $logged[0]->targetPath);
        self::assertSame('revert:test_rule', $logged[0]->ruleName);
        self::assertSame(OperationStatus::Completed, $logged[0]->status);
        self::assertSame(42, $logged[0]->userId);
        self::assertSame([['from' => '/organized/a.png', 'to' => '/source/a.png']], $reverted);
    }

    #[Test]
    public function observerFailureDoesNotTurnACompletedRevertIntoAnExecutionFailure(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(AssetPilotEvents::REVERTED, static fn (): never => throw new \RuntimeException('observer unavailable'));
        $logged = [];

        $result = $this->reverter($this->completedEntry(), $this->asset('/organized/a.png', allowed: true), $dispatcher, $logged)->revertById(1);

        self::assertSame(7, $result->assetId);
        self::assertSame('/source/a.png', $result->toPath);
        self::assertSame('The asset was reverted, but an observer did not complete.', $result->warning);
        self::assertSame(OperationStatus::CompletedWithObserverError, $logged[0]->status);
        self::assertSame('Observer delivery failed.', $logged[0]->errorMessage);
    }

    #[Test]
    public function revertCompletesTheSameDurableAuditEntryThatWasStartedBeforeSave(): void
    {
        $journal = $this->createMock(OperationJournalInterface::class);
        $journal->expects(self::once())
            ->method('begin')
            ->with(self::callback(static fn (OperationIntent $intent): bool => $intent->parentOperationId === 1
                && $intent->sourcePath === '/organized/a.png'
                && $intent->targetPath === '/source/a.png',
            ))
            ->willReturnCallback(static fn (OperationIntent $intent): OperationHandle => new OperationHandle(91, $intent));
        $journal->expects(self::once())
            ->method('complete')
            ->with(self::callback(static fn (OperationHandle $operation): bool => $operation->operationId === 91), OperationStatus::Completed, null)
            ->willReturn(true);

        $result = $this->reverter(
            $this->completedEntry(),
            $this->asset('/organized/a.png', allowed: true),
            journalOverride: $journal,
        )->revertById(1);

        self::assertSame('/source/a.png', $result->toPath);
    }

    #[Test]
    public function failedSaveThatDidNotMoveTheAssetCompletesTheJournalAsFailed(): void
    {
        $logged = [];
        $reason = $this->revertFailure($this->reverter(
            $this->completedEntry(),
            $this->asset('/organized/a.png', allowed: true),
            loggedRef: $logged,
            classification: OperationStatus::Failed,
            saveError: new \RuntimeException('storage unavailable'),
        ), 1);

        self::assertSame(RevertFailure::ExecutionFailed, $reason);
        self::assertSame(OperationStatus::Failed, $logged[0]->status);
    }

    #[Test]
    public function uncertainPersistedStateIsMarkedForRecovery(): void
    {
        $logged = [];
        $reason = $this->revertFailure($this->reverter(
            $this->completedEntry(),
            $this->asset('/organized/a.png', allowed: true),
            loggedRef: $logged,
            classification: OperationStatus::RecoveryRequired,
            saveError: new \RuntimeException('storage result unknown'),
        ), 1);

        self::assertSame(RevertFailure::RecoveryRequired, $reason);
        self::assertSame(OperationStatus::RecoveryRequired, $logged[0]->status);
    }

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
        $asset->method('getId')->willReturn(42);
        $asset->expects(self::once())->method('save')->willReturnCallback(function () use (&$calls, &$asset): Asset {
            $calls[] = 'save';

            return $asset;
        });

        $reverter = new class ($this->createMock(AuditQueryInterface::class), $this->createMock(OperationJournalInterface::class), $loopGuard, new EventDispatcher(), new NullLogger(), $this->authorization(), new LoopGuardedAssetSaver($loopGuard)) extends OperationReverter {
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

    private function asset(string $currentPath, bool $allowed, bool $locked = false): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(7);
        $asset->method('getRealFullPath')->willReturn($currentPath);
        $asset->method('isAllowed')->willReturn($allowed);
        $asset->method('hasProperty')->willReturn($locked);
        $asset->method('getProperty')->willReturn($locked);

        return $asset;
    }

    /**
     * @param array<string, mixed>|null  $entry
     * @param list<MoveOperation>|null    $loggedRef captures the logged revert operations by reference
     */
    private function reverter(
        ?array $entry,
        ?Asset $asset,
        ?EventDispatcher $dispatcher = null,
        ?array &$loggedRef = null,
        ?AuditQueryInterface $auditLoggerOverride = null,
        ?OperationJournalInterface $journalOverride = null,
        OperationStatus $classification = OperationStatus::Completed,
        ?\Throwable $saveError = null,
    ): OperationReverter {
        $auditLogger = $auditLoggerOverride;
        if ($auditLogger === null) {
            $auditLogger = $this->createMock(AuditQueryInterface::class);
            $auditLogger->method('findById')->willReturn($entry);
        }

        $journal = $journalOverride ?? $this->createMock(OperationJournalInterface::class);
        if ($journalOverride === null) {
            $journal->method('begin')->willReturnCallback(
                static fn (OperationIntent $intent): OperationHandle => new OperationHandle(91, $intent),
            );
            $journal->method('complete')->willReturnCallback(static function (
                OperationHandle $handle,
                OperationStatus $status,
                ?string $error = null,
                ?int $duration = null,
            ) use (&$loggedRef): bool {
                if ($loggedRef !== null) {
                    $loggedRef[] = $handle->intent->toMoveOperation($status, $error, $duration);
                }

                return true;
            });
        }

        $folder = $this->createMock(Asset\Folder::class);
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturn(true);
        $loopGuard->method('acquireTarget')->willReturn(true);

        return new class ($auditLogger, $journal, $loopGuard, $dispatcher ?? new EventDispatcher(), new NullLogger(), $this->authorization(), $asset, $folder, $classification, $saveError) extends OperationReverter {
            public function __construct(
                AuditQueryInterface $auditLogger,
                OperationJournalInterface $journal,
                LoopGuard $loopGuard,
                EventDispatcher $dispatcher,
                NullLogger $logger,
                ElementAuthorization $authorization,
                private readonly ?Asset $stubAsset,
                private readonly Asset\Folder $stubFolder,
                private readonly OperationStatus $classification,
                private readonly ?\Throwable $saveError,
            ) {
                parent::__construct($auditLogger, $journal, $loopGuard, $dispatcher, $logger, $authorization, new LoopGuardedAssetSaver($loopGuard));
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

            protected function assetAtPath(string $path): ?Asset
            {
                return null;
            }

            protected function saveReverted(Asset $asset, int $assetId): void
            {
                if ($this->saveError !== null) {
                    throw $this->saveError;
                }
            }

            protected function classifyPersistedRevert(int $assetId, string $currentPath, string $originalPath): OperationStatus
            {
                return $this->classification;
            }

        };
    }

    private function authorization(): ElementAuthorization
    {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (Asset $asset, string $permission): bool => $asset->isAllowed($permission),
        );
        $authorization->method('currentActor')->willReturn(ActorContext::user(42));

        return $authorization;
    }
}
