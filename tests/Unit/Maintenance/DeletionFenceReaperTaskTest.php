<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Maintenance;

use Oronts\AssetPilotBundle\Maintenance\DeletionFenceReaperTask;
use Oronts\AssetPilotBundle\Service\AssetDeletionFenceInterface;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(DeletionFenceReaperTask::class)]
class DeletionFenceReaperTaskTest extends TestCase
{
    #[Test]
    public function reapsUnlockedCandidatesSkipsLockedOnesAndReleasesEveryLockTaken(): void
    {
        $fence = $this->createMock(AssetDeletionFenceInterface::class);
        $fence->method('expiredFenceCandidates')->with(1000)->willReturn([10, 20, 30]);
        $fence->expects(self::exactly(2))->method('reapAsset')->willReturnMap([[10, true], [30, false]]);

        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturnMap([[10, true], [20, false], [30, true]]);
        $released = [];
        $loopGuard->method('releaseAsset')->willReturnCallback(static function (int $id) use (&$released): void {
            $released[] = $id;
        });

        (new DeletionFenceReaperTask($fence, $loopGuard, new NullLogger(), 1000))->execute();

        self::assertSame([10, 30], $released, 'only the locks that were acquired are released');
    }

    #[Test]
    public function aFailingReapIsIsolatedSoTheSweepContinuesAndTheLockIsStillReleased(): void
    {
        $fence = $this->createMock(AssetDeletionFenceInterface::class);
        $fence->method('expiredFenceCandidates')->willReturn([10, 20]);
        $fence->expects(self::exactly(2))->method('reapAsset')->willReturnCallback(
            static fn (int $id): bool => $id === 10 ? throw new \RuntimeException('boom') : true,
        );

        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturn(true);
        $loopGuard->expects(self::exactly(2))->method('releaseAsset');

        (new DeletionFenceReaperTask($fence, $loopGuard, new NullLogger(), 1000))->execute();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function anAcquireFailureIsIsolatedSoTheSweepContinuesWithoutRethrowing(): void
    {
        $fence = $this->createMock(AssetDeletionFenceInterface::class);
        $fence->method('expiredFenceCandidates')->willReturn([10, 20]);
        $fence->expects(self::once())->method('reapAsset')->with(20)->willReturn(true);

        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturnCallback(
            static fn (int $id): bool => 10 === $id ? throw new \RuntimeException('lock store down') : true,
        );
        $loopGuard->expects(self::once())->method('releaseAsset')->with(20);

        (new DeletionFenceReaperTask($fence, $loopGuard, new NullLogger(), 1000))->execute();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function aCandidateListingFailureIsSwallowedAndNoLocksAreTaken(): void
    {
        $fence = $this->createMock(AssetDeletionFenceInterface::class);
        $fence->method('expiredFenceCandidates')->willThrowException(new \RuntimeException('db down'));

        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->expects(self::never())->method('acquireAsset');

        (new DeletionFenceReaperTask($fence, $loopGuard, new NullLogger(), 1000))->execute();

        $this->addToAssertionCount(1);
    }
}
