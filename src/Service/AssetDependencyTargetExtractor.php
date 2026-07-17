<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Service\Query\PimcoreSchema;
use Pimcore\Model\Asset;
use Pimcore\Model\Element\AbstractElement;

/**
 * Resolves the distinct asset IDs an element points at, from its in-memory dependency graph. Shared by
 * the dependency projection (to build edges) and the PRE-save deletion fence (to reject a save that
 * would reference an asset mid-delete), so both observe the exact same target set.
 */
class AssetDependencyTargetExtractor
{
    /** @return list<int> */
    public function extract(AbstractElement $source): array
    {
        $targets = [];
        foreach ($source->resolveDependencies() as $dependency) {
            if (($dependency['type'] ?? null) !== PimcoreSchema::ELEMENT_TYPE_ASSET) {
                continue;
            }
            $targetId = (int) ($dependency['id'] ?? 0);
            if ($targetId > 0 && !($source instanceof Asset && (int) $source->getId() === $targetId)) {
                $targets[$targetId] = true;
            }
        }
        $ids = array_keys($targets);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
