<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\DependencyProjectionSchema;
use Oronts\AssetPilotBundle\Enum\DependencyProjectionState;
use Oronts\AssetPilotBundle\Enum\DependencyUsageVerdict;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Service\AssetDependencyTargetExtractor;
use Oronts\AssetPilotBundle\Service\DbalDependencyProjection;
use Oronts\AssetPilotBundle\Service\DbalDependencyProjectionFreshness;
use Oronts\AssetPilotBundle\Service\DependencyUsageVerifier;
use Oronts\AssetPilotBundle\Service\LiveDependencyUsageScanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\NullLogger;

#[CoversClass(DbalDependencyProjection::class)]
#[CoversClass(DbalDependencyProjectionFreshness::class)]
#[CoversClass(DependencyUsageVerifier::class)]
class DbalDependencyProjectionTest extends TestCase
{
    #[Test]
    public function remainsUnknownUntilACompleteGenerationAndThenReturnsExplicitVerdicts(): void
    {
        $connection = $this->connection();
        $freshness = new DbalDependencyProjectionFreshness($connection);
        $projection = new DbalDependencyProjection($connection, $freshness, new AssetDependencyTargetExtractor());
        $verifier = new DependencyUsageVerifier(
            $projection,
            $freshness,
            $this->createMock(LiveDependencyUsageScanner::class),
            new NullLogger(),
            false,
        );
        $source = $this->source(10, [77]);

        self::assertSame(DependencyProjectionState::BootstrapRequired, $freshness->status()->state);
        $token = $projection->markDirty('object', 10);
        self::assertSame(DependencyUsageVerdict::Unknown, $verifier->verdict($this->asset(88)));
        self::assertTrue($projection->refresh($source, $token));

        $freshness->beginRebuild(true);
        self::assertTrue($projection->refresh($source, $projection->markDirty('object', 10)));
        $freshness->advanceRebuild('complete', 0);
        $status = $freshness->completeRebuild();

        self::assertSame(DependencyProjectionState::Ready, $status->state);
        self::assertSame(1, $status->sourceCount);
        self::assertSame(1, $status->edgeCount);
        self::assertSame(DependencyUsageVerdict::Referenced, $verifier->verdict($this->asset(77)));
        self::assertSame(DependencyUsageVerdict::Safe, $verifier->verdict($this->asset(88)));
        $unchanged = $freshness->beginRebuild();
        self::assertSame(DependencyProjectionState::Ready, $unchanged->state);
        self::assertSame($status->generation, $unchanged->generation);
    }

    #[Test]
    public function failedRebuildResumesItsGenerationAndCursorUnlessRestartIsExplicit(): void
    {
        $freshness = new DbalDependencyProjectionFreshness($this->connection());
        $building = $freshness->beginRebuild();
        $freshness->advanceRebuild('document', 55);
        $freshness->failRebuild('temporary failure');

        $resumed = $freshness->beginRebuild();

        self::assertSame(DependencyProjectionState::Building, $resumed->state);
        self::assertSame($building->generation, $resumed->generation);
        self::assertSame('document', $resumed->cursorType);
        self::assertSame(55, $resumed->cursorId);

        $restarted = $freshness->beginRebuild(true);
        self::assertSame($building->generation + 1, $restarted->generation);
        self::assertSame('object', $restarted->cursorType);
        self::assertSame(0, $restarted->cursorId);
    }

    #[Test]
    public function staleRefreshCannotClearANewerDirtyRevisionAcrossConnections(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'asset-pilot-dependency-');
        self::assertIsString($path);
        try {
            $firstConnection = $this->connection($path);
            $secondConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
            $firstFreshness = new DbalDependencyProjectionFreshness($firstConnection);
            $secondFreshness = new DbalDependencyProjectionFreshness($secondConnection);
            $first = new DbalDependencyProjection($firstConnection, $firstFreshness, new AssetDependencyTargetExtractor());
            $second = new DbalDependencyProjection($secondConnection, $secondFreshness, new AssetDependencyTargetExtractor());
            $source = $this->source(10, [77]);

            $staleToken = $first->markDirty('object', 10);
            $currentToken = $second->markDirty('object', 10);

            self::assertFalse($first->refresh($source, $staleToken));
            self::assertSame(1, $firstFreshness->status()->dirtySources);
            self::assertTrue($second->refresh($source, $currentToken));
            self::assertSame(0, $firstFreshness->status()->dirtySources);
        } finally {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }

    #[Test]
    public function pendingSourceIsAtomicallyPromotedAfterTheElementReceivesItsId(): void
    {
        $connection = $this->connection();
        $freshness = new DbalDependencyProjectionFreshness($connection);
        $projection = new DbalDependencyProjection($connection, $freshness, new AssetDependencyTargetExtractor());
        $pending = $projection->markPending('object');

        self::assertTrue($projection->refresh($this->source(42, [77]), $pending));
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM ' . Installer::TABLE_DEPENDENCY_SOURCE . " WHERE source_key LIKE 'pending:%'",
        ));
        self::assertSame('clean', $connection->fetchOne(
            'SELECT state FROM ' . Installer::TABLE_DEPENDENCY_SOURCE . ' WHERE source_key = ?',
            ['object:42'],
        ));
    }

    #[Test]
    public function referenceSnapshotReadsReferencedAndDirtyAtomically(): void
    {
        $connection = $this->connection();
        $freshness = new DbalDependencyProjectionFreshness($connection);
        $projection = new DbalDependencyProjection($connection, $freshness, new AssetDependencyTargetExtractor());

        $empty = $projection->referenceSnapshot(77);
        self::assertFalse($empty->referenced);
        self::assertFalse($empty->dirty);

        $token = $projection->markDirty('object', 10);
        $pending = $projection->referenceSnapshot(77);
        self::assertFalse($pending->referenced);
        self::assertTrue($pending->dirty, 'a dirty source is observed before its refresh commits');

        self::assertTrue($projection->refresh($this->source(10, [77]), $token));
        $ready = $projection->referenceSnapshot(77);
        self::assertTrue($ready->referenced, 'the committed edge is observed after the refresh');
        self::assertFalse($ready->dirty);
    }

    private function connection(?string $path = null): Connection
    {
        $connection = DriverManager::getConnection($path === null
            ? ['driver' => 'pdo_sqlite', 'memory' => true]
            : ['driver' => 'pdo_sqlite', 'path' => $path]);
        $schema = new Schema();
        DependencyProjectionSchema::ensure($schema);
        foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
            $connection->executeStatement($sql);
        }

        return $connection;
    }

    /** @param list<int> $targetIds */
    private function source(int $id, array $targetIds): AbstractObject
    {
        $source = $this->createMock(AbstractObject::class);
        $source->method('getId')->willReturn($id);
        $source->method('getModificationDate')->willReturn(123);
        $source->method('resolveDependencies')->willReturn(array_map(
            static fn (int $targetId): array => ['id' => $targetId, 'type' => 'asset'],
            $targetIds,
        ));

        return $source;
    }

    private function asset(int $id): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn($id);

        return $asset;
    }
}
