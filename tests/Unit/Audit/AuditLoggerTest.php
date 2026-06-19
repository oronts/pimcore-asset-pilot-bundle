<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Audit;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Audit\AuditLogger;
use Oronts\AssetPilotBundle\Cache\StatsCache;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(AuditLogger::class)]
class AuditLoggerTest extends TestCase
{
    #[Test]
    public function persistsTheActingUserId(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(self::anything(), self::callback(static fn (array $data): bool => ($data['user_id'] ?? 'missing') === 42));

        (new AuditLogger($connection, new NullLogger()))->log($this->operation(42));
    }

    #[Test]
    public function persistsNullUserIdForAutomatedMoves(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(self::anything(), self::callback(static fn (array $data): bool => array_key_exists('user_id', $data) && $data['user_id'] === null));

        (new AuditLogger($connection, new NullLogger()))->log($this->operation(null));
    }

    #[Test]
    public function doesNotWriteWhenDisabled(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('insert');

        (new AuditLogger($connection, new NullLogger(), enabled: false))->log($this->operation(42));
    }

    #[Test]
    public function getStatsIsServedFromCacheWithinTtl(): void
    {
        $logger = new class ($this->createMock(Connection::class), new NullLogger(), true, 90, new StatsCache(new ArrayAdapter()), 60) extends AuditLogger {
            public int $computeCalls = 0;

            protected function computeStats(): array
            {
                ++$this->computeCalls;

                return ['completed' => 1, 'by_class' => []];
            }
        };

        $logger->getStats();
        $logger->getStats();

        self::assertSame(1, $logger->computeCalls, 'the second getStats() is served from cache');
    }

    #[Test]
    public function getStatsAlwaysComputesWhenTtlIsZero(): void
    {
        $logger = new class ($this->createMock(Connection::class), new NullLogger(), true, 90, new StatsCache(new ArrayAdapter()), 0) extends AuditLogger {
            public int $computeCalls = 0;

            protected function computeStats(): array
            {
                ++$this->computeCalls;

                return ['by_class' => []];
            }
        };

        $logger->getStats();
        $logger->getStats();

        self::assertSame(2, $logger->computeCalls, 'ttl 0 disables the cache');
    }

    private function operation(?int $userId): MoveOperation
    {
        return new MoveOperation(
            assetId: 1,
            sourcePath: '/source/file.jpg',
            targetPath: '/target/file.jpg',
            objectId: 2,
            objectClass: 'Product',
            ruleName: 'revert:images',
            status: OperationStatus::Completed,
            triggerType: TriggerType::Manual,
            userId: $userId,
        );
    }
}
