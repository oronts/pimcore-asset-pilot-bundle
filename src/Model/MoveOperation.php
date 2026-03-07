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
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {}

    public function withStatus(OperationStatus $status, ?string $error = null): self
    {
        return new self(
            assetId: $this->assetId,
            sourcePath: $this->sourcePath,
            targetPath: $this->targetPath,
            objectId: $this->objectId,
            objectClass: $this->objectClass,
            ruleName: $this->ruleName,
            status: $status,
            triggerType: $this->triggerType,
            errorMessage: $error ?? $this->errorMessage,
            durationMs: $this->durationMs,
            createdAt: $this->createdAt,
        );
    }

    public function withDuration(int $durationMs): self
    {
        return new self(
            assetId: $this->assetId,
            sourcePath: $this->sourcePath,
            targetPath: $this->targetPath,
            objectId: $this->objectId,
            objectClass: $this->objectClass,
            ruleName: $this->ruleName,
            status: $this->status,
            triggerType: $this->triggerType,
            errorMessage: $this->errorMessage,
            durationMs: $durationMs,
            createdAt: $this->createdAt,
        );
    }
}
