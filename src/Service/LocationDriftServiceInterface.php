<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\DriftItem;
use Pimcore\Model\DataObject\AbstractObject;

interface LocationDriftServiceInterface
{
    /** @return list<DriftItem> */
    public function driftForObject(AbstractObject $object): array;

    /** @return array{items: list<DriftItem>, objectsScanned: int, page: int, limit: int, truncated?: bool} */
    public function driftForClass(string $className, int $page = 1, ?int $limit = null): array;

    /** @return array{items: list<DriftItem>, objectsScanned: int, page: int, limit: int, truncated?: bool}|null */
    public function driftForObjectId(int $objectId): ?array;
}
