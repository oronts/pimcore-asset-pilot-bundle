<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;

readonly class MoveOperation
{
    public function __construct(
        public int $assetId,
        public string $sourcePath,
        public string $targetPath,
        public int $objectId,
        public string $objectClass,
        public string $ruleName,
        public OperationStatus $status,
        public TriggerType $triggerType,
        public ?string $errorMessage = null,
        public ?int $durationMs = null,
        public ?int $userId = null,
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
        public ?string $executionFingerprint = null,
    ) {}
}
