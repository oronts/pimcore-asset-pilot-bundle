<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\MoveOperation;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;

class OrganizePlanFingerprint
{
    /** @param list<MoveOperation> $operations */
    public function forOperations(AbstractObject $object, array $operations): string
    {
        $operationSnapshots = MoveOperationSnapshot::list($operations);

        return hash('sha256', $this->encode([
            'object' => [
                'class' => $object instanceof Concrete ? $object->getClassName() : null,
                'fullPath' => $object->getRealFullPath(),
                'id' => (int) $object->getId(),
                'modifiedAt' => $object->getModificationDate(),
                'type' => $object->getType(),
                'version' => $object->getVersionCount(),
            ],
            'operations' => $operationSnapshots,
        ]));
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
