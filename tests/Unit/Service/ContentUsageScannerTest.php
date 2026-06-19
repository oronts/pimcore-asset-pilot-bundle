<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
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
     * @param string[]                                          $classes
     * @param array<string, bool>                               $matchByTable
     * @param array<string, list<array{0: string, 1: list<string>}>> $columnsByClass
     */
    private function scanner(bool $enabled, array $classes, array $matchByTable = [], array $columnsByClass = []): ContentUsageScanner
    {
        return new class ((new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor(), new NullLogger(), $classes, $enabled, $matchByTable, $columnsByClass) extends ContentUsageScanner {
            /**
             * @param string[]            $classes
             * @param array<string, bool> $matchByTable
             * @param array<string, list<array{0: string, 1: list<string>}>> $columnsByClass
             */
            public function __construct(Connection $c, NullLogger $l, array $classes, bool $enabled, private readonly array $matchByTable, private readonly array $columnsByClass)
            {
                parent::__construct($c, $l, $classes, $enabled);
            }

            protected function textColumnsFor(string $className): array
            {
                return $this->columnsByClass[$className] ?? [];
            }

            protected function matchesAny(string $table, array $columns, string $needle): bool
            {
                return $this->matchByTable[$table] ?? false;
            }
        };
    }

    #[Test]
    public function disabledReturnsFalseEvenWhenContentWouldMatch(): void
    {
        $scanner = $this->scanner(false, ['Product'], ['object_store_P' => true], ['Product' => [['object_store_P', ['body']]]]);

        self::assertFalse($scanner->isReferencedInContent($this->asset()));
    }

    #[Test]
    public function noConfiguredClassesIsInert(): void
    {
        self::assertFalse($this->scanner(true, [])->isReferencedInContent($this->asset()));
    }

    #[Test]
    public function aFolderIsNeverContentReferenced(): void
    {
        $folder = $this->createMock(Asset\Folder::class);
        $scanner = $this->scanner(true, ['Product'], ['object_store_P' => true], ['Product' => [['object_store_P', ['body']]]]);

        self::assertFalse($scanner->isReferencedInContent($folder));
    }

    #[Test]
    public function anEmptyPathIsNeverContentReferenced(): void
    {
        $scanner = $this->scanner(true, ['Product'], ['object_store_P' => true], ['Product' => [['object_store_P', ['body']]]]);

        self::assertFalse($scanner->isReferencedInContent($this->asset('')));
    }

    #[Test]
    public function reportsReferencedWhenAColumnMatches(): void
    {
        $scanner = $this->scanner(
            true,
            ['Product'],
            ['object_store_P' => true],
            ['Product' => [['object_store_P', ['body']]]],
        );

        self::assertTrue($scanner->isReferencedInContent($this->asset()));
    }

    #[Test]
    public function reportsUnreferencedWhenNoColumnMatches(): void
    {
        $scanner = $this->scanner(
            true,
            ['Product'],
            ['object_store_P' => false, 'object_localized_data_P' => false],
            ['Product' => [['object_store_P', ['body']], ['object_localized_data_P', ['teaser']]]],
        );

        self::assertFalse($scanner->isReferencedInContent($this->asset()));
    }

    /**
     * Exercises the REAL matchesAny() body (quoteIdentifier + bound :needle + the fetchOne result /
     * fail-closed branch) against a mocked Connection; only textColumnsFor is stubbed.
     */
    private function realMatchScanner(\Closure $fetchOne): ContentUsageScanner
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        $connection->method('fetchOne')->willReturnCallback($fetchOne);

        return new class ($connection, new NullLogger()) extends ContentUsageScanner {
            public function __construct(Connection $c, NullLogger $l)
            {
                parent::__construct($c, $l, ['Product'], true);
            }

            protected function textColumnsFor(string $className): array
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
                parent::__construct($c, $l, ['Product'], true);
            }

            protected function textColumnsFor(string $className): array
            {
                $this->calls->append($className);

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
}
