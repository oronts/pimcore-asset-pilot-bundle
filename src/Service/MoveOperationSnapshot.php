<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\MoveOperation;

class MoveOperationSnapshot
{
    /** @param list<MoveOperation> $operations @return list<array<string, int|string|null>> */
    public static function list(array $operations): array
    {
        $snapshots = array_map(self::one(...), $operations);
        usort($snapshots, static fn (array $left, array $right): int => self::encode($left) <=> self::encode($right));

        return $snapshots;
    }

    /** @return array<string, int|string|null> */
    private static function one(MoveOperation $operation): array
    {
        return [
            'assetId' => $operation->assetId,
            'error' => $operation->errorMessage,
            'executionFingerprint' => $operation->executionFingerprint,
            'objectClass' => $operation->objectClass,
            'objectId' => $operation->objectId,
            'rule' => $operation->ruleName,
            'source' => $operation->sourcePath,
            'status' => $operation->status->value,
            'target' => $operation->targetPath,
            'trigger' => $operation->triggerType->value,
        ];
    }

    private static function encode(array $snapshot): string
    {
        return json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
