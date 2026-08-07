<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Oronts\AssetPilotBundle\Service\ProjectionMarkerConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectionMarkerConnection::class)]
final class ProjectionMarkerConnectionTest extends TestCase
{
    #[Test]
    public function usesThePrimaryConnectionWhenItIsAlreadyAutocommitting(): void
    {
        $primary = $this->createMock(Connection::class);
        $primary->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
        $primary->method('isTransactionActive')->willReturn(false);
        $primary->method('isAutoCommit')->willReturn(true);

        $provider = new ProjectionMarkerConnection($primary);

        self::assertSame($primary, $provider->forMarker(), 'no ambient transaction -> the marker rides the primary connection');
    }

    #[Test]
    public function usesADedicatedConnectionWhileAnAmbientTransactionIsOpenAndCachesIt(): void
    {
        $primary = $this->createMock(Connection::class);
        $primary->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
        $primary->method('isTransactionActive')->willReturn(true);
        $dedicated = $this->createMock(Connection::class);

        $created = 0;
        $provider = new class ($primary, $dedicated, $created) extends ProjectionMarkerConnection {
            public function __construct(Connection $primary, private readonly Connection $dedicated, private int &$created)
            {
                parent::__construct($primary);
            }

            protected function createDedicatedConnection(): Connection
            {
                ++$this->created;

                return $this->dedicated;
            }
        };

        self::assertSame($dedicated, $provider->forMarker(), 'an open ambient transaction -> a dedicated autocommit connection');
        self::assertSame($dedicated, $provider->forMarker(), 'the dedicated connection is reused');
        self::assertSame(1, $created, 'the dedicated connection is created once and cached');
    }

    #[Test]
    public function usesADedicatedConnectionWhenAutocommitIsDisabled(): void
    {
        $primary = $this->createMock(Connection::class);
        $primary->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
        $primary->method('isTransactionActive')->willReturn(false);
        $primary->method('isAutoCommit')->willReturn(false);
        $dedicated = $this->createMock(Connection::class);

        $provider = new class ($primary, $dedicated) extends ProjectionMarkerConnection {
            public function __construct(Connection $primary, private readonly Connection $dedicated)
            {
                parent::__construct($primary);
            }

            protected function createDedicatedConnection(): Connection
            {
                return $this->dedicated;
            }
        };

        self::assertSame($dedicated, $provider->forMarker());
    }

    #[Test]
    public function alwaysUsesThePrimaryConnectionOnSqliteWhereASecondHandleIsADifferentDatabase(): void
    {
        $primary = $this->createMock(Connection::class);
        $primary->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $primary->method('isTransactionActive')->willReturn(true);

        $provider = new class ($primary) extends ProjectionMarkerConnection {
            protected function createDedicatedConnection(): Connection
            {
                throw new \LogicException('SQLite must never open a dedicated connection.');
            }
        };

        self::assertSame($primary, $provider->forMarker());
    }
}
