<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\DriftItem;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Listing;

class LocationDriftService
{
    public function __construct(
        protected readonly AssetOrganizer $organizer,
        protected readonly ElementAuthorization $authorization,
        protected readonly int $defaultLimit = 50,
    ) {}

    /**
     * @return list<DriftItem>
     */
    public function driftForObject(AbstractObject $object): array
    {
        if (!$this->isVisible($object)) {
            return [];
        }

        return $this->organizer->analyzeDrift($object);
    }

    /**
     * @return array{items: list<DriftItem>, objectsScanned: int, page: int, limit: int}
     */
    public function driftForClass(string $className, int $page = 1, ?int $limit = null): array
    {
        $limit = $limit ?? $this->defaultLimit;
        $page = max(1, $page);
        $offset = ($page - 1) * $limit;

        $items = [];
        $objects = $this->visibleObjects($className, $offset, $limit);
        foreach ($objects as $object) {
            foreach ($this->driftForObject($object) as $driftItem) {
                $items[] = $driftItem;
            }
        }

        return ['items' => $items, 'objectsScanned' => count($objects), 'page' => $page, 'limit' => $limit];
    }

    /**
     * Drift for a single object, in the same shape as driftForClass so callers render it identically.
     *
     * @return array{items: list<DriftItem>, objectsScanned: int, page: int, limit: int}|null null when the object does not exist
     */
    public function driftForObjectId(int $objectId): ?array
    {
        $object = $this->loadObject($objectId);
        if ($object === null || !$this->isVisible($object)) {
            return null;
        }

        return ['items' => $this->driftForObject($object), 'objectsScanned' => 1, 'page' => 1, 'limit' => 1];
    }

    /**
     * @return list<int>
     */
    protected function listObjectIds(string $className, int $offset, int $limit): array
    {
        $listing = new Listing();
        $listing->setObjectTypes([AbstractObject::OBJECT_TYPE_OBJECT, AbstractObject::OBJECT_TYPE_VARIANT]);
        $listing->setCondition('className = ?', [$className]);
        $listing->setOffset($offset);
        $listing->setLimit($limit);

        return array_map('intval', $listing->loadIdList());
    }

    protected function loadObject(int $id): ?AbstractObject
    {
        return AbstractObject::getById($id);
    }

    protected function isVisible(AbstractObject $object): bool
    {
        return $this->authorization->isAllowed($object, 'view');
    }

    /** @return list<AbstractObject> */
    private function visibleObjects(string $className, int $visibleOffset, int $limit): array
    {
        $objects = [];
        $visibleSeen = 0;
        $rawOffset = 0;
        $batchSize = min(500, max(50, $limit));

        do {
            $ids = $this->listObjectIds($className, $rawOffset, $batchSize);
            foreach ($ids as $id) {
                $object = $this->loadObject($id);
                if ($object === null || !$this->isVisible($object)) {
                    continue;
                }
                if ($visibleSeen++ < $visibleOffset) {
                    continue;
                }
                $objects[] = $object;
                if (count($objects) >= $limit) {
                    break 2;
                }
            }
            $rawOffset += $batchSize;
        } while (count($ids) === $batchSize);

        return $objects;
    }
}
