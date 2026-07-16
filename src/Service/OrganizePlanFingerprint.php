<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\MoveOperation;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;

final class OrganizePlanFingerprint
{
    /** @param list<MoveOperation> $operations */
    public function forOperations(AbstractObject $object, array $operations): string
    {
        $operationSnapshots = array_map(static fn (MoveOperation $operation): array => [
            'assetId' => $operation->assetId,
            'error' => $operation->errorMessage,
            'objectClass' => $operation->objectClass,
            'objectId' => $operation->objectId,
            'rule' => $operation->ruleName,
            'source' => $operation->sourcePath,
            'status' => $operation->status->value,
            'target' => $operation->targetPath,
            'trigger' => $operation->triggerType->value,
        ], $operations);
        usort($operationSnapshots, fn (array $left, array $right): int => $this->encode($left) <=> $this->encode($right));

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
