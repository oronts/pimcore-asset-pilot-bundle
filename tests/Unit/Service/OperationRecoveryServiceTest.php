<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\OperationKind;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\OperationHandle;
use Oronts\AssetPilotBundle\Model\OperationIntent;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\OperationJournalInterface;
use Oronts\AssetPilotBundle\Service\OperationRecoveryService;
use Oronts\AssetPilotBundle\Strategy\FirstAssignmentStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Psr\Log\NullLogger;

#[CoversClass(OperationRecoveryService::class)]
final class OperationRecoveryServiceTest extends TestCase
{
    #[Test]
    public function previewClassifiesACommittedMoveWithoutUpdatingTheJournalOrAsset(): void
    {
        $operation = $this->operation(OperationKind::Move);
        $asset = $this->asset('/target/a.jpg');
        $journal = $this->journal([$operation], 25, 120);
        $journal->expects(self::never())->method('complete');
        $authorization = $this->authorization($asset, $operation->intent->actor, true);
        $loopGuard = $this->availableLock($operation->intent->assetId, refresh: false);

        $results = $this->service($journal, $loopGuard, $authorization, $asset, 120)->preview(25);

        self::assertCount(1, $results);
        self::assertSame(OperationStatus::Completed, $results[0]->status);
        self::assertFalse($results[0]->journalUpdated);
        self::assertFalse($results[0]->isResolved());
    }

    #[Test]
    public function recoverFinalizesACommittedMoveAndActivatesItsSuccessOutcome(): void
    {
        $operation = $this->operation(OperationKind::Move);
        $asset = $this->asset('/target/a.jpg');
        $journal = $this->journal([$operation]);
        $journal->expects(self::once())
            ->method('complete')
            ->with($operation, OperationStatus::Completed, null)
            ->willReturn(true);

        $results = $this->service(
            $journal,
            $this->availableLock($operation->intent->assetId),
            $this->authorization($asset, $operation->intent->actor, true),
            $asset,
        )->recover();

        self::assertTrue($results[0]->journalUpdated);
        self::assertTrue($results[0]->isResolved());
    }

    #[Test]
    public function recoverFinalizesAnUncommittedMoveAsFailure(): void
    {
        $operation = $this->operation(OperationKind::Move);
        $asset = $this->asset('/source/a.jpg');
        $journal = $this->journal([$operation]);
        $journal->expects(self::once())
            ->method('complete')
            ->with($operation, OperationStatus::Failed, 'The persisted move did not commit.')
            ->willReturn(true);

        $result = $this->service(
            $journal,
            $this->availableLock($operation->intent->assetId),
            $this->authorization($asset, $operation->intent->actor, true),
            $asset,
        )->recover()[0];

        self::assertSame(OperationStatus::Failed, $result->status);
        self::assertTrue($result->isResolved());
    }

    #[Test]
    public function recoverLeavesAPartiallyCommittedFirstAssignmentForManualRecovery(): void
    {
        $operation = $this->operation(OperationKind::Move, firstAssignment: true);
        $asset = $this->asset('/target/a.jpg', assigned: false);
        $journal = $this->journal([$operation]);
        $journal->expects(self::once())
            ->method('complete')
            ->with($operation, OperationStatus::RecoveryRequired, 'The persisted move state does not match a known postcondition.')
            ->willReturn(true);

        $result = $this->service(
            $journal,
            $this->availableLock($operation->intent->assetId),
            $this->authorization($asset, $operation->intent->actor, true),
            $asset,
        )->recover()[0];

        self::assertSame(OperationStatus::RecoveryRequired, $result->status);
        self::assertTrue($result->journalUpdated);
        self::assertFalse($result->isResolved());
    }

    #[Test]
    public function recoverFinalizesACommittedRevertFromItsPersistedTargetPath(): void
    {
        $operation = $this->operation(OperationKind::Revert);
        $asset = $this->asset('/target/a.jpg');
        $journal = $this->journal([$operation]);
        $journal->expects(self::once())
            ->method('complete')
            ->with($operation, OperationStatus::Completed, null)
            ->willReturn(true);

        $result = $this->service(
            $journal,
            $this->availableLock($operation->intent->assetId),
            $this->authorization($asset, $operation->intent->actor, true),
            $asset,
        )->recover()[0];

        self::assertSame(OperationKind::Revert, $result->kind);
        self::assertTrue($result->isResolved());
    }

    #[Test]
    public function recoverFinalizesAnUncommittedRevertFromItsPersistedSourcePath(): void
    {
        $operation = $this->operation(OperationKind::Revert);
        $asset = $this->asset('/source/a.jpg');
        $journal = $this->journal([$operation]);
        $journal->expects(self::once())
            ->method('complete')
            ->with($operation, OperationStatus::Failed, 'The persisted revert did not commit.')
            ->willReturn(true);

        $result = $this->service(
            $journal,
            $this->availableLock($operation->intent->assetId),
            $this->authorization($asset, $operation->intent->actor, true),
            $asset,
        )->recover()[0];

        self::assertSame(OperationStatus::Failed, $result->status);
        self::assertTrue($result->isResolved());
    }

    #[Test]
    public function recoverUsesThePersistedActorAndDoesNotResolveWhenAuthorizationIsRevoked(): void
    {
        $operation = $this->operation(OperationKind::Move, actor: ActorContext::user(77));
        $asset = $this->asset('/target/a.jpg');
        $journal = $this->journal([$operation]);
        $journal->expects(self::once())
            ->method('complete')
            ->with(
                $operation,
                OperationStatus::RecoveryRequired,
                'The initiating actor is no longer permitted to publish the operation asset.',
            )
            ->willReturn(true);

        $result = $this->service(
            $journal,
            $this->availableLock($operation->intent->assetId),
            $this->authorization($asset, ActorContext::user(77), false),
            $asset,
        )->recover()[0];

        self::assertSame(OperationStatus::RecoveryRequired, $result->status);
        self::assertFalse($result->isResolved());
    }

    #[Test]
    public function recoverDoesNotInspectOrUpdateAnAssetLockedByAnotherWorker(): void
    {
        $operation = $this->operation(OperationKind::Move);
        $journal = $this->journal([$operation]);
        $journal->expects(self::never())->method('complete');
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->expects(self::never())->method('isAllowed');
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->expects(self::once())->method('acquireAsset')->with(7)->willReturn(false);
        $loopGuard->expects(self::never())->method('releaseAsset');

        $result = $this->service($journal, $loopGuard, $authorization, null)->recover()[0];

        self::assertSame(OperationStatus::RecoveryRequired, $result->status);
        self::assertFalse($result->journalUpdated);
        self::assertStringContainsString('busy', $result->message);
    }

    #[Test]
    public function recoverDoesNotGuessWhenTheAssetPathMatchesNeitherPostcondition(): void
    {
        $operation = $this->operation(OperationKind::Move);
        $asset = $this->asset('/elsewhere/a.jpg');
        $journal = $this->journal([$operation]);
        $journal->expects(self::once())
            ->method('complete')
            ->with($operation, OperationStatus::RecoveryRequired, 'The persisted move state does not match a known postcondition.')
            ->willReturn(true);

        $result = $this->service(
            $journal,
            $this->availableLock($operation->intent->assetId),
            $this->authorization($asset, $operation->intent->actor, true),
            $asset,
        )->recover()[0];

        self::assertSame(OperationStatus::RecoveryRequired, $result->status);
        self::assertFalse($result->isResolved());
    }

    #[Test]
    public function recoverReportsAJournalFailureWithoutRepeatingTheMutation(): void
    {
        $operation = $this->operation(OperationKind::Move);
        $asset = $this->asset('/target/a.jpg');
        $journal = $this->journal([$operation]);
        $journal->expects(self::once())->method('complete')->willThrowException(new \RuntimeException('database unavailable'));

        $result = $this->service(
            $journal,
            $this->availableLock($operation->intent->assetId),
            $this->authorization($asset, $operation->intent->actor, true),
            $asset,
        )->recover()[0];

        self::assertSame(OperationStatus::Completed, $result->status);
        self::assertFalse($result->journalUpdated);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $result->fingerprint);
        self::assertStringContainsString('journal could not be updated', $result->message);
    }

    #[Test]
    public function recoverOnlyFinalizesReviewedOperationIds(): void
    {
        $reviewed = $this->operation(OperationKind::Move);
        $unreviewed = new OperationHandle(92, $reviewed->intent);
        $asset = $this->asset('/target/a.jpg');
        $journal = $this->journal([$reviewed, $unreviewed]);
        $journal->expects(self::once())->method('complete')->with($reviewed, OperationStatus::Completed, null)->willReturn(true);

        $results = $this->service(
            $journal,
            $this->availableLock($reviewed->intent->assetId),
            $this->authorization($asset, $reviewed->intent->actor, true),
            $asset,
        )->recover(100, [91]);

        self::assertSame([91], array_column($results, 'operationId'));
    }

    /** @param list<OperationHandle> $operations */
    private function journal(
        array $operations,
        int $expectedLimit = 100,
        int $expectedStaleSeconds = 900,
    ): OperationJournalInterface&MockObject {
        $journal = $this->createMock(OperationJournalInterface::class);
        $journal->expects(self::once())
            ->method('recoverable')
            ->with($expectedLimit, $expectedStaleSeconds)
            ->willReturn($operations);

        return $journal;
    }

    private function operation(
        OperationKind $kind,
        bool $firstAssignment = false,
        ?ActorContext $actor = null,
    ): OperationHandle {
        return new OperationHandle(91, new OperationIntent(
            $kind,
            7,
            '/source/a.jpg',
            '/target/a.jpg',
            12,
            'Product',
            'images',
            TriggerType::Manual,
            $actor ?? ActorContext::system(),
            ['firstAssignment' => $firstAssignment],
            createdAt: new \DateTimeImmutable('2026-07-15 10:00:00 UTC'),
        ));
    }

    private function asset(string $path, bool $assigned = false): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getRealFullPath')->willReturn($path);
        $asset->method('getProperty')->with(FirstAssignmentStrategy::ASSIGNMENT_PROPERTY)->willReturn($assigned);
        $asset->expects(self::never())->method('save');

        return $asset;
    }

    private function authorization(
        Asset $asset,
        ActorContext $actor,
        bool $allowed,
    ): ElementAuthorization&MockObject {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->expects(self::once())
            ->method('isAllowed')
            ->with($asset, 'publish', $actor)
            ->willReturn($allowed);

        return $authorization;
    }

    private function availableLock(int $assetId, bool $refresh = true): LoopGuard&MockObject
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->expects(self::once())->method('acquireAsset')->with($assetId)->willReturn(true);
        $loopGuard->expects($refresh ? self::once() : self::never())->method('refreshAsset')->with($assetId);
        $loopGuard->expects(self::once())->method('releaseAsset')->with($assetId);

        return $loopGuard;
    }

    private function service(
        OperationJournalInterface $journal,
        LoopGuard $loopGuard,
        ElementAuthorization $authorization,
        ?Asset $asset,
        int $recoveryAfterSeconds = 900,
    ): OperationRecoveryService {
        return new class ($journal, $loopGuard, $authorization, new NullLogger(), $recoveryAfterSeconds, $asset) extends OperationRecoveryService {
            public function __construct(
                OperationJournalInterface $journal,
                LoopGuard $loopGuard,
                ElementAuthorization $authorization,
                NullLogger $logger,
                int $recoveryAfterSeconds,
                private readonly ?Asset $asset,
            ) {
                parent::__construct($journal, $loopGuard, $authorization, $logger, $recoveryAfterSeconds);
            }

            protected function loadAsset(int $assetId): ?Asset
            {
                return $this->asset;
            }
        };
    }
}
