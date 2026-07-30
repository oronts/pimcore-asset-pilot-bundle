<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\DriftItem;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Service\Query\BoundedScan;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Listing;

class LocationDriftService implements LocationDriftServiceInterface
{
    public function __construct(
        protected readonly AssetOrganizerInterface $organizer,
        protected readonly ElementAuthorizationInterface $authorization,
        protected readonly int $defaultLimit = 50,
        protected readonly int $maxCandidates = 5000,
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
     * @return array{items: list<DriftItem>, objectsScanned: int, page: int, limit: int, truncated: bool}
     */
    public function driftForClass(string $className, int $page = 1, ?int $limit = null): array
    {
        $limit = $limit ?? $this->defaultLimit;
        $page = max(1, $page);
        $offset = ($page - 1) * $limit;

        $items = [];
        ['objects' => $objects, 'truncated' => $truncated] = $this->visibleObjects($className, $offset, $limit);
        foreach ($objects as $object) {
            foreach ($this->driftForObject($object) as $driftItem) {
                $items[] = $driftItem;
            }
        }

        return ['items' => $items, 'objectsScanned' => count($objects), 'page' => $page, 'limit' => $limit, 'truncated' => $truncated];
    }

    /**
     * Drift for a single object, in the same shape as driftForClass so callers render it identically.
     *
     * @return array{items: list<DriftItem>, objectsScanned: int, page: int, limit: int, truncated: bool}|null null when the object does not exist
     */
    public function driftForObjectId(int $objectId): ?array
    {
        $object = $this->loadObject($objectId);
        if ($object === null || !$this->isVisible($object)) {
            return null;
        }

        return ['items' => $this->driftForObject($object), 'objectsScanned' => 1, 'page' => 1, 'limit' => 1, 'truncated' => false];
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

    /**
     * A workspace-restricted user can hide most of a class, so the raw scan is bounded by
     * {@see $maxCandidates}; a page that cannot be resolved within that budget reports truncated.
     *
     * @return array{objects: list<AbstractObject>, truncated: bool}
     */
    protected function visibleObjects(string $className, int $visibleOffset, int $limit): array
    {
        $objects = [];
        $visibleSeen = 0;

        $truncated = BoundedScan::run(
            fn (int $offset, int $batch): array => $this->listObjectIds($className, $offset, $batch),
            function (int $id) use (&$objects, &$visibleSeen, $visibleOffset, $limit): bool {
                $object = $this->loadObject($id);
                if ($object !== null && $this->isVisible($object) && $visibleSeen++ >= $visibleOffset) {
                    $objects[] = $object;
                }

                return count($objects) >= $limit;
            },
            $this->maxCandidates,
            min(500, max(50, $limit)),
        );

        return ['objects' => $objects, 'truncated' => $truncated];
    }
}
