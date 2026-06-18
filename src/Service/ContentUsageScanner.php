<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Service\Query\Like;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\ClassDefinition\Data\Localizedfields;
use Psr\Log\LoggerInterface;

/**
 * Heuristic guard against deleting an asset that is referenced only inside rich-text / text fields.
 *
 * The dependencies table tracks WYSIWYG *element links* (pimcore_id/pimcore_type), so those are
 * already caught by UnusedAssetFinder. What it misses is a HARD-CODED asset path pasted into a
 * wysiwyg/textarea/input field (e.g. <img src="/Products/x.jpg">). This searches the configured
 * classes' text columns for the asset's path so such an asset is not treated as unused.
 *
 * Opt-in (disabled by default) and used only as a delete/move guard — bounded to the assets actually
 * being mutated, never the listing — because a leading-wildcard LIKE over text columns is a table
 * scan. Nested structures (bricks, blocks, field collections) are not scanned (a known limitation;
 * those live in separate tables). A scan that errors fails CLOSED (treats the asset as referenced),
 * so an opt-in safety check can never let a possibly-referenced asset be deleted on a scan failure.
 */
class ContentUsageScanner
{
    private const array TEXT_FIELD_TYPES = ['wysiwyg', 'textarea', 'input'];

    /**
     * @param string[] $classes DataObject class names to scan (empty = feature inert)
     */
    public function __construct(
        protected readonly Connection $connection,
        protected readonly LoggerInterface $logger,
        protected readonly array $classes = [],
        protected readonly bool $enabled = false,
    ) {}

    public function isReferencedInContent(Asset $asset): bool
    {
        if (!$this->enabled || $this->classes === [] || $asset instanceof Asset\Folder) {
            return false;
        }

        $needle = $asset->getRealFullPath();
        if ($needle === '') {
            return false;
        }

        foreach ($this->classes as $className) {
            foreach ($this->textColumnsFor((string) $className) as [$table, $columns]) {
                if ($columns !== [] && $this->matchesAny($table, $columns, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The text columns to search per class: [table, columns] for the object store and, separately,
     * the localized-data table (localized text fields live there with the same column names).
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    protected function textColumnsFor(string $className): array
    {
        $classDef = ClassDefinition::getByName($className);
        if ($classDef === null) {
            return [];
        }

        $classId = $classDef->getId();
        $store = [];
        $localized = [];
        foreach ($classDef->getFieldDefinitions() as $fieldDef) {
            if ($fieldDef instanceof Localizedfields) {
                foreach ($fieldDef->getFieldDefinitions() as $localizedFieldDef) {
                    if ($this->isTextField($localizedFieldDef)) {
                        $localized[] = $localizedFieldDef->getName();
                    }
                }
                continue;
            }
            if ($this->isTextField($fieldDef)) {
                $store[] = $fieldDef->getName();
            }
        }

        $pairs = [];
        if ($store !== []) {
            $pairs[] = ['object_store_' . $classId, $store];
        }
        if ($localized !== []) {
            $pairs[] = ['object_localized_data_' . $classId, $localized];
        }

        return $pairs;
    }

    /**
     * @param list<string> $columns
     */
    protected function matchesAny(string $table, array $columns, string $needle): bool
    {
        try {
            $predicates = array_map(
                fn (string $column): string => $this->connection->quoteIdentifier($column) . ' LIKE :needle',
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
            ]);

            return true;
        }
    }

    private function isTextField(Data $fieldDef): bool
    {
        return in_array($fieldDef->getFieldType(), self::TEXT_FIELD_TYPES, true);
    }
}
