<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\DependencyExtraction;
use Oronts\AssetPilotBundle\Service\Query\PimcoreSchema;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\Element\AbstractElement;

/**
 * Resolves the distinct asset IDs an element points at, from its in-memory dependency graph. Shared by
 * the dependency projection (to build edges) and the PRE-save deletion fence (to reject a save that
 * would reference an asset mid-delete), so both observe the exact same target set.
 */
class AssetDependencyTargetExtractor implements AssetDependencyTargetExtractorInterface
{
    public function __construct(private readonly AssetFieldExtractorInterface $fieldExtractor) {}

    /**
     * Content fingerprint of the asset edges a source currently resolves to. Stable for an identical edge set
     * and independent of the second-resolution modification date, so the refresh handler can tell a committed
     * same-second change apart from its pre-commit predecessor.
     */
    public function fingerprint(AbstractElement $source): string
    {
        return hash('sha256', implode(',', $this->extract($source)->targetIds));
    }

    public function extract(AbstractElement $source): DependencyExtraction
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
        // resolveDependencies() omits classification-store refs; merge them and propagate completeness so a partial edge set never certifies safe.
        $complete = true;
        if ($source instanceof AbstractObject) {
            $classification = $this->fieldExtractor->classificationStoreAssetIds($source);
            $complete = $classification->complete;
            foreach ($classification->targetIds as $targetId) {
                if ($targetId > 0) {
                    $targets[$targetId] = true;
                }
            }
        }
        $ids = array_keys($targets);
        sort($ids, SORT_NUMERIC);

        return new DependencyExtraction($ids, $complete);
    }
}
