<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Filter;

use Oronts\AssetPilotBundle\Model\Rule;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\LoggerInterface;

class CompositeFilter implements AssetFilterInterface
{
    /** @var AssetFilterInterface[] */
    protected readonly array $filters;

    /** @param iterable<AssetFilterInterface> $filters */
    public function __construct(
        iterable $filters,
        protected readonly LoggerInterface $logger,
    ) {
        $collected = [];
        foreach ($filters as $filter) {
            if ($filter !== $this) {
                $collected[] = $filter;
            }
        }
        $this->filters = $collected;
    }

    public function accept(Asset $asset, AbstractObject $object, Rule $rule): bool
    {
        foreach ($this->filters as $filter) {
            if (!$filter->accept($asset, $object, $rule)) {
                $this->logger->debug('CompositeFilter: asset {id} rejected by {filter}', [
                    'id' => $asset->getId(),
                    'filter' => $filter::class,
                ]);
                return false;
            }
        }

        return true;
    }
}
