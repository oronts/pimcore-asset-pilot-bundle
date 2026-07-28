<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\DependencyExtraction;
use Pimcore\Model\Element\AbstractElement;

/**
 * Resolves the distinct asset IDs an element points at, from its in-memory dependency graph. Shared by the
 * dependency projection (to build edges) and the PRE-save deletion fence (to reject a save that would
 * reference an asset mid-delete), so both observe the exact same target set. Implement (or decorate the
 * default) to customize how an element's asset dependencies are resolved.
 */
interface AssetDependencyTargetExtractorInterface
{
    /** Content fingerprint of the asset edges a source currently resolves to; stable for an identical edge set. */
    public function fingerprint(AbstractElement $source): string;

    public function extract(AbstractElement $source): DependencyExtraction;
}
