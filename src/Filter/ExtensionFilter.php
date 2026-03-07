<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Filter;

use Oronts\AssetPilotBundle\Model\Rule;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\LoggerInterface;

class ExtensionFilter implements AssetFilterInterface
{
    public function __construct(protected readonly LoggerInterface $logger) {}

    public function accept(Asset $asset, AbstractObject $object, Rule $rule): bool
    {
        $allowedExtensions = $rule->filters['extensions'] ?? [];

        if (empty($allowedExtensions)) {
            return true;
        }

        $extension = strtolower(pathinfo($asset->getFilename(), PATHINFO_EXTENSION));
        $accepted = in_array($extension, array_map('strtolower', $allowedExtensions), true);

        $this->logger->debug('ExtensionFilter: asset {id} extension "{ext}" {result}', [
            'id' => $asset->getId(),
            'ext' => $extension,
            'result' => $accepted ? 'accepted' : 'rejected',
        ]);

        return $accepted;
    }
}
