<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Filter;

use Oronts\AssetPilotBundle\Model\Rule;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\LoggerInterface;

class AssetSizeFilter implements AssetFilterInterface
{
    public function __construct(protected readonly LoggerInterface $logger) {}

    public function accept(Asset $asset, AbstractObject $object, Rule $rule): bool
    {
        $minSize = $rule->filters['min_size'] ?? null;
        $maxSize = $rule->filters['max_size'] ?? null;

        if ($minSize === null && $maxSize === null) {
            return true;
        }

        $fileSize = $asset->getFileSize();

        if ($minSize !== null && $fileSize < $minSize) {
            $this->logger->debug('AssetSizeFilter: asset {id} size {size} below minimum {min}', [
                'id' => $asset->getId(),
                'size' => $fileSize,
                'min' => $minSize,
            ]);
            return false;
        }

        if ($maxSize !== null && $fileSize > $maxSize) {
            $this->logger->debug('AssetSizeFilter: asset {id} size {size} above maximum {max}', [
                'id' => $asset->getId(),
                'size' => $fileSize,
                'max' => $maxSize,
            ]);
            return false;
        }

        return true;
    }
}
