<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Maintenance;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\DependencyProjectionSchema;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Maintenance\DependencyProjectionReconcileTask;
use Oronts\AssetPilotBundle\Message\DependencyProjectionRefreshMessage;
use Oronts\AssetPilotBundle\Service\AssetDependencyTargetExtractor;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractor;
use Oronts\AssetPilotBundle\Service\DbalDependencyProjection;
use Oronts\AssetPilotBundle\Service\DbalDependencyProjectionFreshness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(DependencyProjectionReconcileTask::class)]
final class DependencyProjectionReconcileTaskTest extends TestCase
{
    #[Test]
    public function redispatchesStaleDirtySourcesClearsOrphanPendingAndLeavesFreshRowsAlone(): void
    {
        $connection = $this->connection();
        $this->seedSource($connection, 'object:5', 'object', 5, '2020-01-01 00:00:00');
        $this->seedSource($connection, 'object:6', 'object', 6, '2020-12-01 00:00:00');
        $this->seedSource($connection, 'pending:x', 'object', null, '2020-01-01 00:00:00');
        $this->seedSource($connection, 'pending:fresh', 'object', null, '2020-12-01 00:00:00');

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message) use (&$dispatched): Envelope {
            $dispatched[] = $message;

            return new Envelope($message);
        });
        $projection = new DbalDependencyProjection($connection, new DbalDependencyProjectionFreshness($connection), new AssetDependencyTargetExtractor(new AssetFieldExtractor(new NullLogger())));

        $task = new class ($projection, $bus, new NullLogger()) extends DependencyProjectionReconcileTask {
            protected function cutoff(): string
            {
                return '2020-06-01 00:00:00';
            }
        };
        $task->execute();

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(DependencyProjectionRefreshMessage::class, $dispatched[0]);
        self::assertSame('object', $dispatched[0]->sourceType);
        self::assertSame(5, $dispatched[0]->sourceId);
        self::assertSame(0, $this->countRows($connection, 'pending:x'));
        self::assertSame(1, $this->countRows($connection, 'pending:fresh'));
        self::assertSame(1, $this->countRows($connection, 'object:6'));
    }

    private function countRows(Connection $connection, string $key): int
    {
        return (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . Installer::TABLE_DEPENDENCY_SOURCE . ' WHERE source_key = ?', [$key]);
    }

    private function seedSource(Connection $connection, string $key, string $type, ?int $id, string $dirtyAt): void
    {
        $connection->insert(Installer::TABLE_DEPENDENCY_SOURCE, [
            'source_key' => $key,
            'source_type' => $type,
            'source_id' => $id,
            'state' => 'dirty',
            'revision' => 1,
            'generation' => 1,
            'dirty_at' => $dirtyAt,
            'source_modified_at' => null,
            'indexed_at' => null,
            'error_message' => null,
        ]);
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new Schema();
        DependencyProjectionSchema::ensure($schema);
        foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
            $connection->executeStatement($sql);
        }

        return $connection;
    }
}
