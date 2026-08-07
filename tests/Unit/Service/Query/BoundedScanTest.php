<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service\Query;

use Oronts\AssetPilotBundle\Service\Query\BoundedScan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BoundedScan::class)]
class BoundedScanTest extends TestCase
{
    /**
     * @param list<int> $source
     * @param list<int> $visible
     *
     * @return array{collected: list<int>, truncated: bool}
     */
    private function scan(array $source, array $visible, int $limit, int $maxCandidates, int $batchSize = 100): array
    {
        $collected = [];
        $truncated = BoundedScan::run(
            static fn (int $offset, int $length): array => array_slice($source, $offset, $length),
            function (int $id) use (&$collected, $visible, $limit): bool {
                if (in_array($id, $visible, true)) {
                    $collected[] = $id;
                }

                return count($collected) >= $limit;
            },
            $maxCandidates,
            $batchSize,
        );

        return ['collected' => $collected, 'truncated' => $truncated];
    }

    #[Test]
    public function aFilledPageBeforeTheBudgetIsNotTruncated(): void
    {
        $all = range(1, 100);
        $result = $this->scan($all, $all, 5, 5000, 10);

        self::assertSame([1, 2, 3, 4, 5], $result['collected']);
        self::assertFalse($result['truncated']);
    }

    #[Test]
    public function aSourceExhaustedBelowTheBudgetIsNotTruncated(): void
    {
        $result = $this->scan([1, 2, 3], [], 5, 100, 10);

        self::assertSame([], $result['collected']);
        self::assertFalse($result['truncated']);
    }

    #[Test]
    public function aSourceOfExactlyTheBudgetIsExhaustedNotTruncated(): void
    {
        // The regression case: 10 candidates, budget 10, none visible; the scan exhausts the source
        // exactly at the ceiling and must not falsely claim truncation.
        $result = $this->scan(range(1, 10), [], 5, 10, 4);

        self::assertSame([], $result['collected']);
        self::assertFalse($result['truncated']);
    }

    #[Test]
    public function hittingTheBudgetWhileMoreCandidatesRemainIsTruncated(): void
    {
        $result = $this->scan(range(1, 100), [], 5, 10, 4);

        self::assertSame([], $result['collected']);
        self::assertTrue($result['truncated']);
    }

    #[Test]
    public function truncationIsDetectedMidBatch(): void
    {
        $result = $this->scan(range(1, 100), [], 5, 3, 10);

        self::assertTrue($result['truncated']);
    }
}
