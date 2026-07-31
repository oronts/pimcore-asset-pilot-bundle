<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Query;

use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Listing;

/**
 * Stable id window over a DataObject class, shared by every bounded authorized object scan (location
 * drift + the bulk-organize preview). The explicit id-ascending order is required for correctness:
 * offset paging without a deterministic order can skip or duplicate ids between successive windows.
 */
class ObjectClassWindow
{
    /** @return list<int> */
    public static function ids(string $className, int $offset, int $limit): array
    {
        $listing = new Listing();
        $listing->setObjectTypes([AbstractObject::OBJECT_TYPE_OBJECT, AbstractObject::OBJECT_TYPE_VARIANT]);
        $listing->setCondition('className = ?', [$className]);
        $listing->setOrderKey('id');
        $listing->setOrder('asc');
        $listing->setOffset($offset);
        $listing->setLimit($limit);

        return array_map('intval', $listing->loadIdList());
    }
}
