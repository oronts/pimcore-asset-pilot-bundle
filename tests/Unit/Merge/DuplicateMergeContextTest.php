<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Merge;

use Oronts\AssetPilotBundle\Exception\MergeLeaseLostException;
use Oronts\AssetPilotBundle\Merge\DuplicateMergeContext;
use Oronts\AssetPilotBundle\Model\ActorContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;

#[CoversClass(DuplicateMergeContext::class)]
final class DuplicateMergeContextTest extends TestCase
{
    #[Test]
    public function heartbeatSurvivesADispositionExceedingBothDefaultLeaseIntervals(): void
    {
        $now = 0;
        $lockLease = 60;
        $itemLease = 300;
        $refreshes = 0;
        $saved = new \ArrayObject();
        $context = $this->context(
            function () use (&$now, &$lockLease, &$itemLease, &$refreshes): void {
                if ($now > min($lockLease, $itemLease)) {
                    throw new MergeLeaseLostException('lease lost');
                }
                $lockLease = $now + 60;
                $itemLease = $now + 300;
                $refreshes++;
            },
            function (callable $mutator) use ($saved): void {
                $mutator($this->asset(9));
                $saved->append(9);
            },
        );

        for ($i = 0; $i < 12; $i++) {
            $now += 45;
            $context->heartbeat();
        }
        self::assertSame(12, $refreshes);

        $context->save(static function (Asset $asset): void {});
        self::assertCount(1, $saved);
    }

    #[Test]
    public function saveFailsClosedAndDoesNotWriteAfterOwnershipLapses(): void
    {
        $now = 0;
        $writes = 0;
        $context = $this->context(
            function () use (&$now): void {
                if ($now > 60) {
                    throw new MergeLeaseLostException('lease lost');
                }
            },
            function (callable $mutator) use (&$writes): void {
                $mutator($this->asset(9));
                $writes++;
            },
        );
        $now = 90;

        $this->expectException(MergeLeaseLostException::class);
        try {
            $context->save(static function (): void {});
        } finally {
            self::assertSame(0, $writes);
        }
    }

    #[Test]
    public function exposesStableIdentityAndIdempotencyKeyAcrossRetries(): void
    {
        $first = $this->context(static fn () => null, static fn () => null, 'run-a');
        $second = $this->context(static fn () => null, static fn () => null, 'run-b');

        self::assertSame('run-a', $first->operationId());
        self::assertSame('asset:9', $first->itemKey());
        self::assertSame(9, $first->copyId());
        self::assertSame(3, $first->canonicalId());
        self::assertSame('duplicate-merge:abc:9', $first->idempotencyKey());
        self::assertSame($first->idempotencyKey(), $second->idempotencyKey());
    }

    #[Test]
    public function rejectsBlankIdentity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DuplicateMergeContext('', 'asset:9', 9, 3, 1, ActorContext::system(), 'duplicate-merge:abc:9', static fn () => null, static fn () => null);
    }

    private function context(\Closure $heartbeat, \Closure $save, string $runId = 'run-a'): DuplicateMergeContext
    {
        return new DuplicateMergeContext($runId, 'asset:9', 9, 3, 1, ActorContext::system(), 'duplicate-merge:abc:9', $heartbeat, $save);
    }

    private function asset(int $id): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn($id);

        return $asset;
    }
}
