<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\AssetFieldInfo;
use Oronts\AssetPilotBundle\Model\DependencyExtraction;
use Pimcore\Model\DataObject\AbstractObject;

interface AssetFieldExtractorInterface
{
    /** @return AssetFieldInfo[] */
    public function extract(AbstractObject $object): array;

    /**
     * Asset IDs referenced through the object's classification-store fields (which Pimcore does not record as
     * dependency edges), plus whether that traversal was COMPLETE. Used by dependency extraction so the
     * projection and pre-save deletion fence can fail closed on a partial read rather than treat a
     * still-referenced asset as safe to delete.
     */
    public function classificationStoreAssetIds(AbstractObject $object): DependencyExtraction;
}
