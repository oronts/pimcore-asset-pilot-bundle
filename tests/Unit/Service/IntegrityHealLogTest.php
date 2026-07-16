<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Security\ActorContextProvider;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\IntegrityHealLog;
use Oronts\AssetPilotBundle\Service\Query\AssetWorkspaceQueryScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(IntegrityHealLog::class)]
class IntegrityHealLogTest extends TestCase
{
    #[Test]
    public function markUndoneReportsWhetherTheRowWasUpdated(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('update')
            ->willReturnOnConsecutiveCalls(1, 0);
        $log = $this->log($connection);

        self::assertTrue($log->markUndone(5));
        self::assertFalse($log->markUndone(6));
    }

    #[Test]
    public function markUndoneReportsPersistenceFailure(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('update')->willThrowException(new \RuntimeException('database unavailable'));

        self::assertFalse($this->log($connection)->markUndone(5));
    }

    #[Test]
    public function reversibleHistoryIsWorkspaceScopedOrderedAndPaginated(): void
    {
        $connection = $this->historyConnection();
        $this->insertAsset($connection, 10, '/Products/', 'one.jpg');
        $this->insertAsset($connection, 20, '/Products/', 'two.jpg');
        $this->insertHistory($connection, 10, IntegrityHealLog::STATUS_HEALED, 1, '2026-07-14 10:00:00');
        $this->insertHistory($connection, 20, IntegrityHealLog::STATUS_UNDONE, 2, '2026-07-15 10:00:00');
        $this->insertHistory($connection, 10, IntegrityHealLog::STATUS_PENDING, 3, '2026-07-16 10:00:00');
        $this->insertHistory($connection, 10, IntegrityHealLog::STATUS_HEALED, null, '2026-07-17 10:00:00');

        $first = $this->log($connection)->getReversibleHistory(1, 1);
        $second = $this->log($connection)->getReversibleHistory(2, 1);

        self::assertSame(2, $first['total']);
        self::assertSame(2, $first['pages']);
        self::assertSame('/Products/two.jpg', $first['items'][0]['path']);
        self::assertSame(IntegrityHealLog::STATUS_UNDONE, $first['items'][0]['status']);
        self::assertFalse($first['items'][0]['is_current']);
        self::assertSame('/Products/one.jpg', $second['items'][0]['path']);
        self::assertTrue($second['items'][0]['is_current']);
    }

    #[Test]
    public function reversibleHistoryFailsClosedForAnonymousActors(): void
    {
        $connection = $this->historyConnection();
        $this->insertAsset($connection, 10, '/Products/', 'one.jpg');
        $this->insertHistory($connection, 10, IntegrityHealLog::STATUS_HEALED, 1, '2026-07-14 10:00:00');

        $history = $this->log($connection, ActorContext::anonymous())->getReversibleHistory();

        self::assertSame(0, $history['total']);
        self::assertSame([], $history['items']);
    }

    private function log(Connection $connection, ?ActorContext $actor = null): IntegrityHealLog
    {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn($actor ?? ActorContext::system());

        return new IntegrityHealLog(
            $connection,
            new NullLogger(),
            new AssetWorkspaceQueryScope($connection, $authorization, $this->createMock(ActorContextProvider::class)),
        );
    }

    private function historyConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE assets (id INTEGER PRIMARY KEY, path VARCHAR(765) NOT NULL, filename VARCHAR(255) NOT NULL)');
        $connection->executeStatement('CREATE TABLE asset_pilot_integrity_log (id INTEGER PRIMARY KEY AUTOINCREMENT, asset_id INTEGER NOT NULL, from_version INTEGER NULL, to_version INTEGER NULL, checker VARCHAR(100) NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL)');

        return $connection;
    }

    private function insertAsset(Connection $connection, int $id, string $path, string $filename): void
    {
        $connection->insert('assets', ['id' => $id, 'path' => $path, 'filename' => $filename]);
    }

    private function insertHistory(Connection $connection, int $assetId, string $status, ?int $fromVersion, string $createdAt): void
    {
        $connection->insert(Installer::TABLE_INTEGRITY_LOG, [
            'asset_id' => $assetId,
            'from_version' => $fromVersion,
            'to_version' => $fromVersion === null ? null : $fromVersion + 10,
            'checker' => 'image',
            'status' => $status,
            'created_at' => $createdAt,
        ]);
    }
}
