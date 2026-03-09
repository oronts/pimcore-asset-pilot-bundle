<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Strategy;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Strategy\FirstAssignmentStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\NullLogger;

#[CoversClass(FirstAssignmentStrategy::class)]
class FirstAssignmentStrategyTest extends TestCase
{
    private function createRule(): Rule
    {
        return new Rule(
            name: 'test', class: 'Product', fields: [], condition: null,
            targetPath: '/test', strategy: MoveStrategy::FirstAssignment, callback: null,
            priority: 10, enabled: true, filters: [],
        );
    }

    #[Test]
    public function allowsMoveWhenNoAuditEntries(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('0');

        $strategy = new FirstAssignmentStrategy($connection, new NullLogger());

        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(42);
        $object = $this->createMock(AbstractObject::class);

        self::assertTrue($strategy->resolve($asset, $object, $this->createRule()));
    }

    #[Test]
    public function rejectsMoveWhenAuditEntriesExist(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('3');

        $strategy = new FirstAssignmentStrategy($connection, new NullLogger());

        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(42);
        $object = $this->createMock(AbstractObject::class);

        self::assertFalse($strategy->resolve($asset, $object, $this->createRule()));
    }

    #[Test]
    public function rethrowsDatabaseException(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('DB error'));

        $strategy = new FirstAssignmentStrategy($connection, new NullLogger());

        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(42);
        $object = $this->createMock(AbstractObject::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB error');

        $strategy->resolve($asset, $object, $this->createRule());
    }

    #[Test]
    public function supportsFirstAssignmentStrategy(): void
    {
        $connection = $this->createMock(Connection::class);
        $strategy = new FirstAssignmentStrategy($connection, new NullLogger());

        self::assertTrue($strategy->supports(MoveStrategy::FirstAssignment));
    }

    #[Test]
    public function doesNotSupportAlwaysStrategy(): void
    {
        $connection = $this->createMock(Connection::class);
        $strategy = new FirstAssignmentStrategy($connection, new NullLogger());

        self::assertFalse($strategy->supports(MoveStrategy::Always));
    }

    #[Test]
    public function queriesCorrectSqlWithAssetId(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with(
                'SELECT COUNT(*) FROM asset_pilot_audit_log WHERE asset_id = :assetId AND status = :status',
                ['assetId' => 99, 'status' => 'completed'],
            )
            ->willReturn('0');

        $strategy = new FirstAssignmentStrategy($connection, new NullLogger());

        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(99);
        $object = $this->createMock(AbstractObject::class);

        $strategy->resolve($asset, $object, $this->createRule());
    }
}
