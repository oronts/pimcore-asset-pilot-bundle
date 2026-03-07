<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Filter;

use Oronts\AssetPilotBundle\Model\Rule;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\LoggerInterface;

class AssetTypeFilter implements AssetFilterInterface
{
    public function __construct(protected readonly LoggerInterface $logger) {}

    public function accept(Asset $asset, AbstractObject $object, Rule $rule): bool
    {
        $allowedTypes = $rule->filters['types'] ?? [];

        if (empty($allowedTypes)) {
            return true;
        }

        $assetType = $asset->getType();
        $accepted = in_array($assetType, $allowedTypes, true);

        $this->logger->debug('AssetTypeFilter: asset {id} type "{type}" {result}', [
            'id' => $asset->getId(),
            'type' => $assetType,
            'result' => $accepted ? 'accepted' : 'rejected',
            'allowed' => $allowedTypes,
        ]);

        return $accepted;
    }
}
