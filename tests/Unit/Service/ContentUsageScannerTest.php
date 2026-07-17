<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Oronts\AssetPilotBundle\Service\ContentUsageScanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Psr\Log\NullLogger;

#[CoversClass(ContentUsageScanner::class)]
class ContentUsageScannerTest extends TestCase
{
    private function asset(string $path = '/p/x.jpg'): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getRealFullPath')->willReturn($path);

        return $asset;
    }

    /**
     * @param array<string, bool>                         $matchByTable
     * @param list<array{0: string, 1: list<string>}>     $globalColumns
     */
    private function scanner(bool $enabled, array $matchByTable = [], array $globalColumns = []): ContentUsageScanner
    {
        return new class ((new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor(), new NullLogger(), $enabled, $matchByTable, $globalColumns) extends ContentUsageScanner {
            /**
             * @param array<string, bool> $matchByTable
             * @param list<array{0: string, 1: list<string>}> $globalColumns
             */
            public function __construct(Connection $c, NullLogger $l, bool $enabled, private readonly array $matchByTable, private readonly array $globalColumns)
            {
                parent::__construct($c, $l, $enabled);
            }

            protected function matchesAny(string $table, array $columns, string $needle): bool
            {
                return $this->matchByTable[$table] ?? false;
            }

            protected function discoverGlobalContentColumns(): array
            {
                return $this->globalColumns;
            }
        };
    }

    #[Test]
    public function disabledReturnsFalseEvenWhenContentWouldMatch(): void
    {
        $scanner = $this->scanner(false, ['object_store_P' => true], [['object_store_P', ['body']]]);

        self::assertFalse($scanner->isReferencedInContent($this->asset()));
    }

    #[Test]
    public function enabledGlobalScanDoesNotRequireAClassAllowlist(): void
    {
        self::assertFalse($this->scanner(true)->isReferencedInContent($this->asset()));
    }

    #[Test]
    public function verificationRequiresTheFeatureToBeEnabled(): void
    {
        self::assertFalse($this->scanner(false)->canVerify());
        self::assertTrue($this->scanner(true)->canVerify());
    }

    #[Test]
    public function aFolderIsNeverContentReferenced(): void
    {
        $folder = $this->createMock(Asset\Folder::class);
        $scanner = $this->scanner(true, ['object_store_P' => true], [['object_store_P', ['body']]]);

        self::assertFalse($scanner->isReferencedInContent($folder));
    }

    #[Test]
    public function anEmptyPathIsNeverContentReferenced(): void
    {
        $scanner = $this->scanner(true, ['object_store_P' => true], [['object_store_P', ['body']]]);

        self::assertFalse($scanner->isReferencedInContent($this->asset('')));
    }

    #[Test]
    public function reportsReferencedWhenAColumnMatches(): void
    {
        $scanner = $this->scanner(
            true,
            ['object_store_P' => true],
            [['object_store_P', ['body']]],
        );

        self::assertTrue($scanner->isReferencedInContent($this->asset()));
    }

    #[Test]
    public function globalDiscoveryFindsPathsInsideNestedObjectTables(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE object_brick_Gallery_Product (o_id INTEGER, caption TEXT)');
        $connection->insert('object_brick_Gallery_Product', ['o_id' => 1, 'caption' => '<img src="/p/x.jpg">']);

        $scanner = new ContentUsageScanner($connection, new NullLogger(), enabled: true);

        self::assertTrue($scanner->isReferencedInContent($this->asset()));
    }

    #[Test]
    public function reportsUnreferencedWhenNoColumnMatches(): void
    {
        $scanner = $this->scanner(
            true,
            ['object_store_P' => false, 'object_localized_data_P' => false],
            [['object_store_P', ['body']], ['object_localized_data_P', ['teaser']]],
        );

        self::assertFalse($scanner->isReferencedInContent($this->asset()));
    }

    /**
     * Exercises the REAL matchesAny() body (quoteIdentifier + bound :needle + the fetchOne result /
     * fail-closed branch) against a mocked Connection.
     */
    private function realMatchScanner(\Closure $fetchOne): ContentUsageScanner
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        $connection->method('fetchOne')->willReturnCallback($fetchOne);

        return new class ($connection, new NullLogger()) extends ContentUsageScanner {
            public function __construct(Connection $c, NullLogger $l)
            {
                parent::__construct($c, $l, true);
            }

            protected function discoverGlobalContentColumns(): array
            {
                return [['object_store_P', ['body']]];
            }
        };
    }

    #[Test]
    public function matchesAnyReportsReferencedWhenTheQueryFindsARow(): void
    {
        $scanner = $this->realMatchScanner(static fn (): string => '1');

        self::assertTrue($scanner->isReferencedInContent($this->asset()));
    }

    #[Test]
    public function matchesAnyReportsUnreferencedWhenTheQueryFindsNothing(): void
    {
        $scanner = $this->realMatchScanner(static fn (): false => false);

        self::assertFalse($scanner->isReferencedInContent($this->asset()));
    }

    #[Test]
    public function matchesAnyFailsClosedWhenTheQueryThrows(): void
    {
        $scanner = $this->realMatchScanner(static fn (): never => throw new \RuntimeException('db down'));

        self::assertTrue($scanner->isReferencedInContent($this->asset()));
    }

    #[Test]
    public function memoizesColumnDiscoveryUntilResetClearsIt(): void
    {
        $calls = new \ArrayObject();
        $scanner = new class ((new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor(), new NullLogger(), $calls) extends ContentUsageScanner {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(Connection $c, NullLogger $l, private readonly \ArrayObject $calls)
            {
                parent::__construct($c, $l, true);
            }

            protected function discoverGlobalContentColumns(): array
            {
                $this->calls->append('global');

                return [];
            }
        };

        $scanner->isReferencedInContent($this->asset());
        $scanner->isReferencedInContent($this->asset());
        self::assertCount(1, $calls, 'column discovery is memoized across scans within a batch');

        $scanner->reset();
        $scanner->isReferencedInContent($this->asset());
        self::assertCount(2, $calls, 'reset() forces re-discovery on the next scan');
    }

    #[Test]
    public function memoizesPathResultsUntilResetClearsThem(): void
    {
        $calls = new \ArrayObject();
        $scanner = new class ((new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor(), new NullLogger(), $calls) extends ContentUsageScanner {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(Connection $c, NullLogger $l, private readonly \ArrayObject $calls)
            {
                parent::__construct($c, $l, true);
            }

            protected function discoverGlobalContentColumns(): array
            {
                return [['object_store_P', ['body']]];
            }

            protected function matchesAny(string $table, array $columns, string $needle): bool
            {
                $this->calls->append($needle);

                return false;
            }
        };

        $scanner->isReferencedInContent($this->asset('/p/a.jpg'));
        $scanner->isReferencedInContent($this->asset('/p/a.jpg'));
        $scanner->isReferencedInContent($this->asset('/p/b.jpg'));
        self::assertSame(['/p/a.jpg', '/p/b.jpg'], $calls->getArrayCopy());

        $scanner->reset();
        $scanner->isReferencedInContent($this->asset('/p/a.jpg'));
        self::assertSame(['/p/a.jpg', '/p/b.jpg', '/p/a.jpg'], $calls->getArrayCopy());
    }

    #[Test]
    public function freshlyReferencedInContentReScansInsteadOfReusingAStaleNegativeCache(): void
    {
        $scans = new \ArrayObject();
        /** @var \ArrayObject<string, bool> $results */
        $results = new \ArrayObject(['/p/a.jpg' => false]);
        $scanner = new class ((new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor(), new NullLogger(), $scans, $results) extends ContentUsageScanner {
            /**
             * @param \ArrayObject<int, string> $scans
             * @param \ArrayObject<string, bool> $results
             */
            public function __construct(Connection $c, NullLogger $l, private readonly \ArrayObject $scans, private readonly \ArrayObject $results)
            {
                parent::__construct($c, $l, true);
            }

            protected function discoverGlobalContentColumns(): array
            {
                return [['object_store_P', ['body']]];
            }

            protected function matchesAny(string $table, array $columns, string $needle): bool
            {
                $this->scans->append($needle);

                return $this->results[$needle] ?? false;
            }
        };

        // A pre-fence caller (e.g. fingerprinting) caches a negative result.
        self::assertFalse($scanner->isReferencedInContent($this->asset('/p/a.jpg')));

        // A content reference is then committed between that cache population and the fenced re-read.
        $results['/p/a.jpg'] = true;

        self::assertFalse($scanner->isReferencedInContent($this->asset('/p/a.jpg')), 'the stale negative is still cached');
        self::assertTrue($scanner->freshlyReferencedInContent($this->asset('/p/a.jpg')), 'the fresh re-read re-scans and observes the new reference');
        self::assertSame(['/p/a.jpg', '/p/a.jpg'], $scans->getArrayCopy(), 'only the initial and the fresh read scan; the cached read does not');
    }
}
