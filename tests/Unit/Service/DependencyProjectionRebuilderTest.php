<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Enum\DependencyProjectionState;
use Oronts\AssetPilotBundle\Model\DependencyProjectionStatus;
use Oronts\AssetPilotBundle\Model\DependencySourceToken;
use Oronts\AssetPilotBundle\Service\DependencyProjectionFreshnessInterface;
use Oronts\AssetPilotBundle\Service\DependencyProjectionInterface;
use Oronts\AssetPilotBundle\Service\DependencyProjectionRebuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\Element\AbstractElement;
use Psr\Log\NullLogger;

#[CoversClass(DependencyProjectionRebuilder::class)]
class DependencyProjectionRebuilderTest extends TestCase
{
    #[Test]
    public function readyProjectionIsAnIdempotentNoOpWithoutRestart(): void
    {
        $ready = $this->projectionStatus(DependencyProjectionState::Ready);
        $freshness = $this->freshness($ready);
        $projection = $this->createMock(DependencyProjectionInterface::class);
        $projection->expects(self::never())->method('markDirty');

        $result = $this->rebuilder($projection, $freshness, [], [])->rebuildBatch(100);

        self::assertTrue($result->completed);
        self::assertSame(0, $result->processed);
    }

    #[Test]
    public function completesAResumableGenerationAcrossAllSourceTypes(): void
    {
        $projection = $this->createMock(DependencyProjectionInterface::class);
        $projection->method('markDirty')->willReturnCallback(
            static fn (string $type, int $id): DependencySourceToken => new DependencySourceToken($type . ':' . $id, 1),
        );
        $projection->method('refresh')->willReturn(true);
        $freshness = $this->freshness($this->projectionStatus(DependencyProjectionState::Building, 'object'));
        $freshness->expects(self::once())->method('completeRebuild')->willReturn($this->projectionStatus(DependencyProjectionState::Ready));
        $source = $this->createMock(AbstractObject::class);

        $rebuilder = $this->rebuilder($projection, $freshness, ['object' => [1, 2]], [1 => $source, 2 => $source]);
        $result = $rebuilder->rebuildBatch(10);

        self::assertTrue($result->completed);
        self::assertSame(2, $result->processed);
        self::assertSame(0, $result->failed);
    }

    #[Test]
    public function processesNoMoreThanTheRequestedBatchSize(): void
    {
        $projection = $this->createMock(DependencyProjectionInterface::class);
        $projection->method('markDirty')->willReturnCallback(
            static fn (string $type, int $id): DependencySourceToken => new DependencySourceToken($type . ':' . $id, 1),
        );
        $projection->method('refresh')->willReturn(true);
        $building = $this->projectionStatus(DependencyProjectionState::Building, 'object');
        $freshness = $this->freshness($building);
        $freshness->expects(self::never())->method('completeRebuild');
        $source = $this->createMock(AbstractObject::class);

        $result = $this->rebuilder($projection, $freshness, ['object' => [1, 2, 3]], [1 => $source, 2 => $source, 3 => $source])
            ->rebuildBatch(2);

        self::assertFalse($result->completed);
        self::assertSame(2, $result->processed);
    }

    #[Test]
    public function keepsTheGenerationUnreadyWhenASourceRefreshFails(): void
    {
        $projection = $this->createMock(DependencyProjectionInterface::class);
        $projection->method('markDirty')->willReturn(new DependencySourceToken('object:1', 1));
        $projection->method('refresh')->willReturn(false);
        $building = $this->projectionStatus(DependencyProjectionState::Building, 'object', 1);
        $freshness = $this->freshness($building);
        $freshness->expects(self::never())->method('completeRebuild');

        $result = $this->rebuilder(
            $projection,
            $freshness,
            ['object' => [1]],
            [1 => $this->createMock(AbstractObject::class)],
        )->rebuildBatch(10);

        self::assertFalse($result->completed);
        self::assertSame(1, $result->failed);
    }

    private function freshness(DependencyProjectionStatus $building): DependencyProjectionFreshnessInterface
    {
        $freshness = $this->createMock(DependencyProjectionFreshnessInterface::class);
        $freshness->method('beginRebuild')->willReturn($building);
        $freshness->method('status')->willReturn($building);

        return $freshness;
    }

    /** @param array<string, list<int>> $ids @param array<int, AbstractElement> $sources */
    private function rebuilder(
        DependencyProjectionInterface $projection,
        DependencyProjectionFreshnessInterface $freshness,
        array $ids,
        array $sources,
    ): DependencyProjectionRebuilder {
        return new class ($this->createMock(Connection::class), $projection, $freshness, new NullLogger(), $ids, $sources) extends DependencyProjectionRebuilder {
            /** @param array<string, list<int>> $ids @param array<int, AbstractElement> $sources */
            public function __construct(Connection $connection, DependencyProjectionInterface $projection, DependencyProjectionFreshnessInterface $freshness, NullLogger $logger, private readonly array $ids, private readonly array $sources)
            {
                parent::__construct($connection, $projection, $freshness, $logger);
            }

            protected function sourceIds(string $sourceType, int $afterId, int $limit): array
            {
                return array_slice(array_values(array_filter(
                    $this->ids[$sourceType] ?? [],
                    static fn (int $id): bool => $id > $afterId,
                )), 0, $limit);
            }

            protected function loadSource(string $sourceType, int $sourceId): ?AbstractElement
            {
                return $this->sources[$sourceId] ?? null;
            }
        };
    }

    private function projectionStatus(DependencyProjectionState $state, ?string $cursor = null, int $dirty = 0): DependencyProjectionStatus
    {
        return new DependencyProjectionStatus($state, 1, $dirty, 0, 0, $cursor, 0, null, null, null);
    }
}
