<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\DependencyProjectionSchema;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Message\DependencyProjectionRefreshMessage;
use Oronts\AssetPilotBundle\MessageHandler\DependencyProjectionRefreshHandler;
use Oronts\AssetPilotBundle\Model\DependencySourceToken;
use Oronts\AssetPilotBundle\Service\AssetDependencyTargetExtractor;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractor;
use Oronts\AssetPilotBundle\Service\DbalDependencyProjection;
use Oronts\AssetPilotBundle\Service\DbalDependencyProjectionFreshness;
use Oronts\AssetPilotBundle\Service\DependencyProjectionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\Element\AbstractElement;
use Psr\Log\NullLogger;

#[CoversClass(DependencyProjectionRefreshHandler::class)]
class DependencyProjectionRefreshHandlerTest extends TestCase
{
    #[Test]
    public function refreshesAForceLoadedCurrentSourceBehindANewRevisionFence(): void
    {
        $source = $this->createMock(AbstractObject::class);
        $token = new DependencySourceToken('object:7', 3);
        $calls = [];
        $projection = $this->createMock(DependencyProjectionInterface::class);
        $projection->expects(self::once())->method('markDirty')->with('object', 7)->willReturnCallback(
            static function () use ($token, &$calls): DependencySourceToken {
                $calls[] = 'markDirty';

                return $token;
            },
        );
        $projection->expects(self::once())->method('refresh')->with($source, $token)->willReturnCallback(
            static function () use (&$calls): bool {
                $calls[] = 'refresh';

                return true;
            },
        );

        ($this->handler($projection, static function () use ($source, &$calls): AbstractElement {
            $calls[] = 'load';

            return $source;
        }))(new DependencyProjectionRefreshMessage('object', 7));

        self::assertSame(['markDirty', 'load', 'refresh'], $calls);
    }

    #[Test]
    public function removesAMissingSourceOnlyBehindTheEstablishedRevisionFence(): void
    {
        $token = new DependencySourceToken('document:8', 4);
        $projection = $this->createMock(DependencyProjectionInterface::class);
        $projection->expects(self::once())->method('markDirty')->with('document', 8)->willReturn($token);
        $projection->expects(self::once())->method('remove')->with('document', 8, $token);

        ($this->handler($projection, null))(new DependencyProjectionRefreshMessage('document', 8));
    }

    #[Test]
    public function olderWorkerCannotCertifyStaleSourceDataAsReady(): void
    {
        $this->withConcurrentProjections(function (
            Connection $connection,
            DbalDependencyProjection $older,
            DbalDependencyProjection $newer,
        ): void {
            $handler = $this->handler($older, function () use ($newer): AbstractElement {
                $newer->markDirty('object', 7);

                return $this->source(7, [51]);
            });

            $this->expectException(\RuntimeException::class);
            try {
                $handler(new DependencyProjectionRefreshMessage('object', 7));
            } finally {
                self::assertSame('dirty', $connection->fetchOne(
                    'SELECT state FROM ' . Installer::TABLE_DEPENDENCY_SOURCE . ' WHERE source_key = ?',
                    ['object:7'],
                ));
                self::assertSame(2, (int) $connection->fetchOne(
                    'SELECT revision FROM ' . Installer::TABLE_DEPENDENCY_SOURCE . ' WHERE source_key = ?',
                    ['object:7'],
                ));
                self::assertSame(0, (int) $connection->fetchOne(
                    'SELECT COUNT(*) FROM ' . Installer::TABLE_DEPENDENCY_EDGE . ' WHERE source_key = ?',
                    ['object:7'],
                ));
            }
        });
    }

    #[Test]
    public function olderDeleteWorkerCannotEraseARecreatedSource(): void
    {
        $this->withConcurrentProjections(function (
            Connection $connection,
            DbalDependencyProjection $older,
            DbalDependencyProjection $newer,
        ): void {
            $handler = $this->handler($older, function () use ($newer): ?AbstractElement {
                $recreated = $this->source(7, [91]);
                self::assertTrue($newer->refresh($recreated, $newer->markDirty('object', 7)));

                return null;
            });

            $handler(new DependencyProjectionRefreshMessage('object', 7));

            self::assertSame('clean', $connection->fetchOne(
                'SELECT state FROM ' . Installer::TABLE_DEPENDENCY_SOURCE . ' WHERE source_key = ?',
                ['object:7'],
            ));
            self::assertSame(2, (int) $connection->fetchOne(
                'SELECT revision FROM ' . Installer::TABLE_DEPENDENCY_SOURCE . ' WHERE source_key = ?',
                ['object:7'],
            ));
            self::assertSame([91], array_map('intval', $connection->fetchFirstColumn(
                'SELECT target_asset_id FROM ' . Installer::TABLE_DEPENDENCY_EDGE . ' WHERE source_key = ?',
                ['object:7'],
            )));
        });
    }

    #[Test]
    public function refusesToMarkCleanUntilTheSourceReachesTheExpectedCommittedRevision(): void
    {
        $source = $this->createMock(AbstractObject::class);
        $source->method('getId')->willReturn(7);
        $source->method('getModificationDate')->willReturn(100);
        $token = new DependencySourceToken('object:7', 3);
        $projection = $this->createMock(DependencyProjectionInterface::class);
        $projection->expects(self::once())->method('markDirty')->with('object', 7)->willReturn($token);
        $projection->expects(self::never())->method('refresh');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ahead of the committed source revision/');

        ($this->handler($projection, $source))(new DependencyProjectionRefreshMessage('object', 7, 200));
    }

    #[Test]
    public function marksCleanOnceTheSourceReachesTheExpectedCommittedRevision(): void
    {
        $source = $this->createMock(AbstractObject::class);
        $source->method('getId')->willReturn(7);
        $source->method('getModificationDate')->willReturn(200);
        $token = new DependencySourceToken('object:7', 3);
        $projection = $this->createMock(DependencyProjectionInterface::class);
        $projection->method('markDirty')->willReturn($token);
        $projection->expects(self::once())->method('refresh')->with($source, $token)->willReturn(true);

        ($this->handler($projection, $source))(new DependencyProjectionRefreshMessage('object', 7, 200));
    }

    #[Test]
    public function retriesInsteadOfRemovingWhenAnExpectedSourceIsNotYetVisible(): void
    {
        $projection = $this->createMock(DependencyProjectionInterface::class);
        $projection->method('markDirty')->willReturn(new DependencySourceToken('object:7', 3));
        $projection->expects(self::never())->method('remove');
        $projection->expects(self::never())->method('refresh');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot see the expected committed source/');

        ($this->handler($projection, null))(new DependencyProjectionRefreshMessage('object', 7, 200));
    }

    #[Test]
    public function marksCleanWhenTheVisibleContentMatchesTheCommittedFingerprint(): void
    {
        $source = $this->source(7, [51], 200);
        $fingerprint = (new AssetDependencyTargetExtractor(new AssetFieldExtractor(new NullLogger())))->fingerprint($source);
        $token = new DependencySourceToken('object:7', 3);
        $projection = $this->createMock(DependencyProjectionInterface::class);
        $projection->method('markDirty')->willReturn($token);
        $projection->expects(self::once())->method('refresh')->with($source, $token)->willReturn(true);

        ($this->handler($projection, $source))(new DependencyProjectionRefreshMessage('object', 7, 200, $fingerprint));
    }

    #[Test]
    public function retriesWhenTheVisibleContentIsPreCommitAtTheSameSecond(): void
    {
        $committedFingerprint = (new AssetDependencyTargetExtractor(new AssetFieldExtractor(new NullLogger())))->fingerprint($this->source(7, [91], 200));
        $visibleOldSource = $this->source(7, [51], 200);
        $projection = $this->createMock(DependencyProjectionInterface::class);
        $projection->method('markDirty')->willReturn(new DependencySourceToken('object:7', 3));
        $projection->expects(self::never())->method('refresh');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/pre-commit content/');

        ($this->handler($projection, $visibleOldSource))(new DependencyProjectionRefreshMessage('object', 7, 200, $committedFingerprint));
    }

    #[Test]
    public function discardsWhenTheSourceAdvancedPastTheExpectedRevision(): void
    {
        $committedFingerprint = (new AssetDependencyTargetExtractor(new AssetFieldExtractor(new NullLogger())))->fingerprint($this->source(7, [91], 200));
        $advancedSource = $this->source(7, [51], 205);
        $projection = $this->createMock(DependencyProjectionInterface::class);
        $projection->method('markDirty')->willReturn(new DependencySourceToken('object:7', 3));
        $projection->expects(self::never())->method('refresh');
        $projection->expects(self::never())->method('remove');

        ($this->handler($projection, $advancedSource))(new DependencyProjectionRefreshMessage('object', 7, 200, $committedFingerprint));
    }

    private function handler(DependencyProjectionInterface $projection, AbstractElement|\Closure|null $source): DependencyProjectionRefreshHandler
    {
        return new class ($projection, $source) extends DependencyProjectionRefreshHandler {
            public function __construct(
                DependencyProjectionInterface $projection,
                private readonly AbstractElement|\Closure|null $source,
            ) {
                parent::__construct($projection, new AssetDependencyTargetExtractor(new AssetFieldExtractor(new NullLogger())));
            }

            protected function loadSource(string $sourceType, int $sourceId): ?AbstractElement
            {
                return $this->source instanceof \Closure ? ($this->source)() : $this->source;
            }
        };
    }

    /** @param \Closure(Connection, DbalDependencyProjection, DbalDependencyProjection): void $test */
    private function withConcurrentProjections(\Closure $test): void
    {
        $path = tempnam(sys_get_temp_dir(), 'asset-pilot-dependency-handler-');
        self::assertIsString($path);
        try {
            $firstConnection = $this->connection($path);
            $secondConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
            $test(
                $firstConnection,
                new DbalDependencyProjection($firstConnection, new DbalDependencyProjectionFreshness($firstConnection), new AssetDependencyTargetExtractor(new AssetFieldExtractor(new NullLogger()))),
                new DbalDependencyProjection($secondConnection, new DbalDependencyProjectionFreshness($secondConnection), new AssetDependencyTargetExtractor(new AssetFieldExtractor(new NullLogger()))),
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function connection(string $path): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        $schema = new Schema();
        DependencyProjectionSchema::ensure($schema);
        foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
            $connection->executeStatement($sql);
        }

        return $connection;
    }

    /** @param list<int> $targetIds */
    private function source(int $id, array $targetIds, int $modificationDate = 123): AbstractObject
    {
        $source = $this->createMock(AbstractObject::class);
        $source->method('getId')->willReturn($id);
        $source->method('getModificationDate')->willReturn($modificationDate);
        $source->method('resolveDependencies')->willReturn(array_map(
            static fn (int $targetId): array => ['id' => $targetId, 'type' => 'asset'],
            $targetIds,
        ));

        return $source;
    }
}
