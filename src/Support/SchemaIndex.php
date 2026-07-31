<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Support;

use Doctrine\DBAL\Schema\Table;

/**
 * Idempotently declare a Doctrine DBAL index on a schema table: skip when an identical index already exists, and
 * replace it when its columns or uniqueness have changed. Shared by the installer and the dependency projection.
 */
final class SchemaIndex
{
    /** @param list<string> $columns */
    public static function ensure(Table $table, string $name, array $columns, bool $unique = false): void
    {
        if ($table->hasIndex($name)) {
            $index = $table->getIndex($name);
            if ($index->getColumns() === $columns && $index->isUnique() === $unique) {
                return;
            }
            $table->dropIndex($name);
        }

        if ($unique) {
            $table->addUniqueIndex($columns, $name);

            return;
        }

        $table->addIndex($columns, $name);
    }
}
