<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use Oronts\AssetPilotBundle\Service\RunItemLease;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunItemLease::class)]
final class RunItemLeaseTest extends TestCase
{
    #[Test]
    public function startClaimsTheItemUnderAMintedTokenAndKeepsTheLease(): void
    {
        $guard = $this->createMock(LoopGuard::class);
        $guard->expects(self::once())->method('beginOperationRunItemLease')->with('run', 'object:1')->willReturn('tok-1');
        $guard->expects(self::never())->method('releaseOperationRunItem');
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())->method('startItem')->with('run', 'object:1', 'tok-1')->willReturn(true);

        self::assertTrue((new RunItemLease($guard, $runs))->start('run', 'object:1'));
    }

    #[Test]
    public function startReleasesTheLocalLeaseWhenTheDurableClaimIsRejected(): void
    {
        $guard = $this->createMock(LoopGuard::class);
        $guard->method('beginOperationRunItemLease')->willReturn('tok-1');
        $guard->expects(self::once())->method('releaseOperationRunItem')->with('run', 'object:1');
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('startItem')->willReturn(false);

        self::assertFalse((new RunItemLease($guard, $runs))->start('run', 'object:1'), 'a rejected durable claim must not report an owned item');
    }

    #[Test]
    public function completeReturnsTrueAndReleasesWhenTheFencedCompletionWins(): void
    {
        $guard = $this->createMock(LoopGuard::class);
        $guard->method('operationRunItemToken')->willReturn('tok-1');
        $guard->expects(self::once())->method('releaseOperationRunItem')->with('run', 'object:1');
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())->method('completeItem')
            ->with('run', 'object:1', OperationRunItemStatus::Completed, ['operationCount' => 2], null, 'tok-1')
            ->willReturn(true);

        self::assertTrue((new RunItemLease($guard, $runs))->complete('run', 'object:1', OperationRunItemStatus::Completed, ['operationCount' => 2]));
    }

    #[Test]
    public function completeReturnsFalseWhenAConcurrentReclaimOwnsTheItem(): void
    {
        $guard = $this->createMock(LoopGuard::class);
        $guard->method('operationRunItemToken')->willReturn('stale-tok');
        $guard->expects(self::once())->method('releaseOperationRunItem');
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('completeItem')->willReturn(false);

        self::assertFalse(
            (new RunItemLease($guard, $runs))->complete('run', 'object:1', OperationRunItemStatus::Completed),
            'a completion that lost its fenced claim must report failure so the caller does not claim durable success',
        );
    }
}
