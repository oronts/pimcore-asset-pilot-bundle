<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * Returns the primary connection while it is already autocommitting (the common case, zero overhead), and a
 * lazily-created, cached, dedicated autocommit connection to the same database while a consumer-owned
 * transaction is open. SQLite is exempt: a second in-memory handle is a different database and a file
 * database has a single writer, so there is no cross-connection visibility race to solve.
 */
class ProjectionMarkerConnection implements ProjectionMarkerConnectionInterface
{
    private ?Connection $dedicated = null;

    public function __construct(protected readonly Connection $primary) {}

    public function forMarker(): Connection
    {
        if (!$this->requiresDedicatedConnection()) {
            return $this->primary;
        }

        return $this->dedicated ??= $this->createDedicatedConnection();
    }

    public function publicationIsDeferred(): bool
    {
        return $this->requiresDedicatedConnection();
    }

    protected function requiresDedicatedConnection(): bool
    {
        if ($this->primary->getDatabasePlatform() instanceof SQLitePlatform) {
            return false;
        }

        return $this->primary->isTransactionActive() || !$this->primary->isAutoCommit();
    }

    protected function createDedicatedConnection(): Connection
    {
        return DriverManager::getConnection($this->primary->getParams(), $this->primary->getConfiguration());
    }
}
