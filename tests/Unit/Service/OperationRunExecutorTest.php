<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Enum\OperationRunKind;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Merge\CopyDisposition;
use Oronts\AssetPilotBundle\Merge\MergeOutcome;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Security\ActorContextProvider;
use Oronts\AssetPilotBundle\Security\ActorContextStore;
use Oronts\AssetPilotBundle\Service\DuplicateMergeService;
use Oronts\AssetPilotBundle\Service\OperationRunExecutor;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperationRunExecutor::class)]
final class OperationRunExecutorTest extends TestCase
{
    #[Test]
    public function dispatchesObjectRetriesWithThePersistedActor(): void
    {
        $actor = ActorContext::user(7);
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatchObject')->with(
            42,
            TriggerType::Api,
            $actor,
            'retry-run',
            'fingerprint-42',
        );
        $executor = new OperationRunExecutor(
            $dispatcher,
            $this->createMock(DuplicateMergeService::class),
            $this->actors(),
        );

        $result = $executor->execute(OperationRunKind::Organize, 'retry-run', [
            'request_payload' => ['trigger' => 'api'],
            'items' => [[
                'target_type' => 'data_object',
                'target_id' => 42,
                'fingerprint' => 'fingerprint-42',
            ]],
        ], $actor);

        self::assertSame(OperationRunStatus::Queued, $result->status);
        self::assertTrue($result->asynchronous);
    }

    #[Test]
    public function restoresThePersistedActorWhileResumingDuplicateMerge(): void
    {
        $actor = ActorContext::user(7);
        $actors = $this->actors();
        $merge = $this->createMock(DuplicateMergeService::class);
        $merge->expects(self::once())->method('resume')->with('retry-run')->willReturnCallback(
            function () use ($actor, $actors): MergeOutcome {
                self::assertEquals($actor, $actors->current());

                return new MergeOutcome(
                    'checksum',
                    3,
                    [new CopyDisposition(9, DispositionOutcome::Deleted)],
                    'retry-run',
                    OperationRunStatus::Completed,
                );
            },
        );
        $executor = new OperationRunExecutor(
            $this->createMock(OrganizeDispatcher::class),
            $merge,
            $actors,
        );

        $result = $executor->execute(OperationRunKind::DuplicateMerge, 'retry-run', [], $actor);

        self::assertSame(OperationRunStatus::Completed, $result->status);
        self::assertFalse($result->asynchronous);
        self::assertSame(9, $result->dispositions[0]->copyId);
        self::assertEquals(ActorContext::system(), $actors->current());
    }

    #[Test]
    public function rejectsUnsupportedTargetsBeforeDispatch(): void
    {
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatchObject');
        $dispatcher->expects(self::never())->method('dispatchBulk');
        $executor = new OperationRunExecutor(
            $dispatcher,
            $this->createMock(DuplicateMergeService::class),
            $this->actors(),
        );

        $this->expectException(\InvalidArgumentException::class);

        $executor->execute(OperationRunKind::Organize, 'retry-run', [
            'items' => [['target_type' => 'asset', 'target_id' => 42]],
        ], ActorContext::system());
    }

    private function actors(): ActorContextStore
    {
        $provider = $this->createMock(ActorContextProvider::class);
        $provider->method('current')->willReturn(ActorContext::system());

        return new ActorContextStore($provider);
    }
}
