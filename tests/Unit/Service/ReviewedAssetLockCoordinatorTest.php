<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\ReviewedAssetLockCoordinator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewedAssetLockCoordinator::class)]
class ReviewedAssetLockCoordinatorTest extends TestCase
{
    #[Test]
    public function normalizesIdsAcquiresAllAndReleasesInReverseOrder(): void
    {
        $calls = [];
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturnCallback(static function (int $id) use (&$calls): bool {
            $calls[] = 'acquire:' . $id;

            return true;
        });
        $loopGuard->method('releaseAsset')->willReturnCallback(static function (int $id) use (&$calls): void {
            $calls[] = 'release:' . $id;
        });
        $coordinator = new ReviewedAssetLockCoordinator($loopGuard);

        $result = $coordinator->run(
            [3, 1, 3, 2],
            static fn (int $id): \Throwable => new StaleApplyPlanException((string) $id),
            static fn (array $ids): array => $ids,
        );

        self::assertSame([1, 2, 3], $result);
        self::assertSame([
            'acquire:1',
            'acquire:2',
            'acquire:3',
            'release:3',
            'release:2',
            'release:1',
        ], $calls);
    }

    #[Test]
    public function partialAcquireFailureReleasesOnlyHeldLocksAndDoesNotRunOperation(): void
    {
        $released = [];
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturnCallback(static fn (int $id): bool => $id !== 2);
        $loopGuard->method('releaseAsset')->willReturnCallback(static function (int $id) use (&$released): void {
            $released[] = $id;
        });
        $operationCalled = false;

        try {
            (new ReviewedAssetLockCoordinator($loopGuard))->run(
                [1, 2, 3],
                static fn (int $id): \Throwable => new StaleApplyPlanException('busy:' . $id),
                static function () use (&$operationCalled): void {
                    $operationCalled = true;
                },
            );
            self::fail('Expected the busy asset to reject the reviewed operation.');
        } catch (StaleApplyPlanException $e) {
            self::assertSame('busy:2', $e->getMessage());
        }

        self::assertFalse($operationCalled);
        self::assertSame([1], $released);
    }

    #[Test]
    public function operationFailureStillReleasesEveryLockInReverseOrder(): void
    {
        $released = [];
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturn(true);
        $loopGuard->method('releaseAsset')->willReturnCallback(static function (int $id) use (&$released): void {
            $released[] = $id;
        });

        $this->expectException(\RuntimeException::class);
        try {
            (new ReviewedAssetLockCoordinator($loopGuard))->run(
                [2, 1],
                static fn (int $id): \Throwable => new StaleApplyPlanException((string) $id),
                static fn (): never => throw new \RuntimeException('failed'),
            );
        } finally {
            self::assertSame([2, 1], $released);
        }
    }

    #[Test]
    public function refreshesTheStableLockSetInOrder(): void
    {
        $refreshed = [];
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('refreshAsset')->willReturnCallback(static function (int $id) use (&$refreshed): void {
            $refreshed[] = $id;
        });

        (new ReviewedAssetLockCoordinator($loopGuard))->refresh([1, 2, 3]);

        self::assertSame([1, 2, 3], $refreshed);
    }

    #[Test]
    public function rejectsNonPositiveIdsBeforeAcquiringAnything(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->expects(self::never())->method('acquireAsset');
        $coordinator = new ReviewedAssetLockCoordinator($loopGuard);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('positive element IDs');
        $coordinator->run(
            [1, 0],
            static fn (int $id): \Throwable => new StaleApplyPlanException((string) $id),
            static function (): void {},
        );
    }
}
