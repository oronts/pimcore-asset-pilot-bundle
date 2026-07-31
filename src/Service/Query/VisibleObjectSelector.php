<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Query;

use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Support\BulkIds;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;

class VisibleObjectSelector implements VisibleObjectSelectorInterface
{
    public function __construct(
        private readonly ElementAuthorizationInterface $authorization,
        private readonly int $objectScanBudget = 5000,
    ) {}

    public function page(string $className, int $visibleOffset, int $limit): array
    {
        $rows = [];
        $visibleSeen = 0;

        $truncated = BoundedScan::run(
            fn (int $offset, int $batch): array => $this->listObjectIds($className, $offset, $batch),
            function (int $id) use (&$rows, &$visibleSeen, $visibleOffset, $limit): bool {
                $object = $this->loadObject($id);
                if ($object !== null && $this->authorization->isAllowed($object, 'view') && $visibleSeen++ >= $visibleOffset) {
                    $rows[] = [
                        'id' => (int) $object->getId(),
                        'key' => (string) $object->getKey(),
                        'className' => $object instanceof Concrete ? $object->getClassName() : null,
                    ];
                }

                return count($rows) > $limit;
            },
            $this->objectScanBudget,
            min(500, max(50, $limit)),
        );

        return [
            'objects' => array_slice($rows, 0, $limit),
            'hasMore' => count($rows) > $limit,
            'truncated' => $truncated,
        ];
    }

    public function resolveIds(string $className): array
    {
        $ids = [];
        $truncated = BoundedScan::run(
            fn (int $offset, int $batch): array => $this->listObjectIds($className, $offset, $batch),
            function (int $id) use (&$ids): bool {
                $object = $this->loadObject($id);
                if ($object !== null && $this->authorization->isAllowed($object, 'view')) {
                    $ids[] = (int) $object->getId();
                }

                return count($ids) > BulkIds::MAX;
            },
            $this->objectScanBudget,
            500,
        );

        return ['ids' => $ids, 'truncated' => $truncated];
    }

    /** @return list<int> */
    protected function listObjectIds(string $className, int $offset, int $limit): array
    {
        return ObjectClassWindow::ids($className, $offset, $limit);
    }

    protected function loadObject(int $id): ?AbstractObject
    {
        return AbstractObject::getById($id);
    }
}
