<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;
use Oronts\AssetPilotBundle\Service\Query\Like;
use Oronts\AssetPilotBundle\Service\Query\PimcoreSchema;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Heuristic guard against deleting an asset that is referenced only inside rich-text / text fields.
 *
 * The dependencies table tracks WYSIWYG *element links* (pimcore_id/pimcore_type), so those are
 * already caught by UnusedAssetFinder. What it misses is a HARD-CODED asset path pasted into a
 * wysiwyg/textarea/input field (e.g. <img src="/Products/x.jpg">). This searches Pimcore content
 * tables for the asset's path so such an asset is not treated as unused.
 *
 * Opt-in (disabled by default) and used only as a delete/move guard. Global schema discovery covers
 * object stores, nested structures, documents, properties, and classification-store text columns.
 * A scan error fails closed so an unverifiable asset is never authorized for mutation.
 */
class ContentUsageScanner implements ContentUsageScannerInterface, ResetInterface
{
    /** @var list<array{0: string, 1: list<string>}>|null */
    private ?array $globalColumns = null;

    /** @var array<string, bool> */
    private array $referencesByPath = [];

    public function __construct(
        protected readonly Connection $connection,
        protected readonly LoggerInterface $logger,
        protected readonly bool $enabled = false,
    ) {}

    /**
     * Rebuild schema discovery for each worker message so new content tables and columns are included.
     */
    public function reset(): void
    {
        $this->globalColumns = null;
        $this->referencesByPath = [];
    }

    public function canVerify(): bool
    {
        return $this->enabled;
    }

    public function isReferencedInContent(Asset $asset): bool
    {
        $needle = $this->needle($asset);
        if ($needle === null) {
            return false;
        }
        if (array_key_exists($needle, $this->referencesByPath)) {
            return $this->referencesByPath[$needle];
        }

        return $this->referencesByPath[$needle] = $this->scan($needle);
    }

    public function freshlyReferencedInContent(Asset $asset): bool
    {
        $needle = $this->needle($asset);
        if ($needle === null) {
            return false;
        }

        // Ignore (and refresh) any result cached before a fenced safety re-read; a negative cached before
        // the fence was acquired must not authorize a delete against a reference committed in that window.
        return $this->referencesByPath[$needle] = $this->scan($needle);
    }

    private function needle(Asset $asset): ?string
    {
        if (!$this->canVerify() || $asset instanceof Asset\Folder) {
            return null;
        }
        $needle = $asset->getRealFullPath();

        return $needle === '' ? null : $needle;
    }

    /** The actual global content-table scan; fails closed (returns true) on any discovery/scan error. */
    protected function scan(string $needle): bool
    {
        try {
            $this->globalColumns ??= $this->discoverGlobalContentColumns();
            foreach ($this->globalColumns as [$table, $columns]) {
                if ($columns !== [] && $this->matchesAny($table, $columns, $needle)) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: global content-reference discovery failed; destructive actions remain blocked.', [
                'exception' => $e,
            ]);

            return true;
        }

        return false;
    }

    /** @return list<array{0: string, 1: list<string>}> */
    protected function discoverGlobalContentColumns(): array
    {
        $tables = [];
        $schema = $this->connection->createSchemaManager();
        foreach ($schema->listTableNames() as $table) {
            if (!$this->isContentTable($table)) {
                continue;
            }

            $columns = [];
            foreach ($schema->listTableColumns($table) as $column) {
                $type = $column->getType();
                if ($type instanceof StringType || $type instanceof TextType || $type instanceof JsonType) {
                    $columns[] = $column->getName();
                }
            }
            $columns = $this->contentColumnsFor($table, $columns);
            if ($columns !== []) {
                $tables[] = [$table, $columns];
            }
        }

        return $tables;
    }

    /**
     * Narrow a content table's string columns to the ones that can hold a hard-coded asset path. The properties
     * table's structural columns (cpath/cid/ctype/name/type) are metadata: cpath stores the OWNER element's own
     * path, so scanning it would make every asset that has a property self-match its own path. Only `data` holds a
     * value a user could paste a path into.
     *
     * @param list<string> $columnNames
     * @return list<string>
     */
    protected function contentColumnsFor(string $table, array $columnNames): array
    {
        if ($table !== PimcoreSchema::TABLE_PROPERTIES) {
            return $columnNames;
        }

        return array_values(array_filter($columnNames, static fn (string $name): bool => $name === 'data'));
    }

    private function isContentTable(string $table): bool
    {
        return $table === PimcoreSchema::TABLE_PROPERTIES
            || str_starts_with($table, 'object_')
            || str_starts_with($table, 'documents_')
            || str_starts_with($table, 'classificationstore_');
    }

    /**
     * @param list<string> $columns
     */
    protected function matchesAny(string $table, array $columns, string $needle): bool
    {
        try {
            $predicates = array_map(
                fn (string $column): string => $this->connection->quoteIdentifier($column) . ' LIKE :needle' . Like::CLAUSE,
                $columns,
            );
            $sql = sprintf(
                'SELECT 1 FROM %s WHERE %s LIMIT 1',
                $this->connection->quoteIdentifier($table),
                implode(' OR ', $predicates),
            );

            return $this->connection->fetchOne($sql, ['needle' => '%' . Like::escape($needle) . '%']) !== false;
        } catch (\Throwable $e) {
            // Fail closed: a content scan that cannot confirm safety treats the asset as referenced.
            $this->logger->warning('Asset Pilot: content-usage scan failed for {table}; treating the asset as referenced. {error}', [
                'table' => $table,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return true;
        }
    }
}
