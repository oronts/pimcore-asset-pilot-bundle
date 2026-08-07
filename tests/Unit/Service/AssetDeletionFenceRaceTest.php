<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\DependencyProjectionSchema;
use Oronts\AssetPilotBundle\Service\DbalAssetDeletionFence;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Element\ValidationException;

/**
 * Two independent connections over one shared database prove the committed cross-session guarantee: once
 * a deleting session owns an asset's fence, a concurrent writing session's asset-referencing save is
 * rejected until the fence is released. This is the engine-agnostic core of the delete/save race; the
 * MySQL row-locking and same-second zero-changed-row behaviours are covered by external live acceptance.
 */
#[CoversClass(DbalAssetDeletionFence::class)]
class AssetDeletionFenceRaceTest extends TestCase
{
    #[Test]
    public function aConcurrentWriterIsRejectedWhileAnotherSessionOwnsTheDeletionFence(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'asset-pilot-fence-race-');
        self::assertIsString($path);

        try {
            $deleter = $this->sharedConnection($path, true);
            $writer = $this->sharedConnection($path, false);
            $deleter->insert('assets', ['id' => 88, 'path' => '/x/', 'filename' => 'a.jpg', 'type' => 'image']);

            $deleterFence = new DbalAssetDeletionFence($deleter);
            $writerFence = new DbalAssetDeletionFence($writer);

            $writerFence->assertWritableTargets([88]);
            $this->addToAssertionCount(1);

            $token = $deleterFence->acquire(88, 'unused_delete');
            self::assertNotNull($token);

            try {
                $writerFence->assertWritableTargets([88]);
                self::fail('expected the fenced asset to be rejected for the concurrent writer');
            } catch (ValidationException $e) {
                self::assertStringContainsString('being deleted', $e->getMessage());
            }

            $deleterFence->release(88, $token);
            $writerFence->assertWritableTargets([88]);
            $this->addToAssertionCount(1);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function sharedConnection(string $path, bool $materialize): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        if ($materialize) {
            $schema = new Schema();
            DependencyProjectionSchema::ensure($schema);
            foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
                $connection->executeStatement($sql);
            }
            $connection->executeStatement('CREATE TABLE assets (id INTEGER PRIMARY KEY, path TEXT, filename TEXT, type TEXT)');
        }

        return $connection;
    }
}
