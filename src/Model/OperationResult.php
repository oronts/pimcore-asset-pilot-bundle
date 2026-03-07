<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\OperationStatus;

readonly class OperationResult
{
    protected function __construct(
        public OperationStatus $status,
        public string $message,
        public ?MoveOperation $operation,
        public ?int $durationMs,
    ) {}

    public static function success(MoveOperation $operation): self
    {
        return new self(
            status: OperationStatus::Completed,
            message: sprintf('Asset %d moved to %s', $operation->assetId, $operation->targetPath),
            operation: $operation,
            durationMs: $operation->durationMs,
        );
    }

    public static function skipped(string $reason, MoveOperation $operation): self
    {
        return new self(
            status: OperationStatus::Skipped,
            message: $reason,
            operation: $operation,
            durationMs: $operation->durationMs,
        );
    }

    public static function failed(string $reason, MoveOperation $operation): self
    {
        return new self(
            status: OperationStatus::Failed,
            message: $reason,
            operation: $operation,
            durationMs: $operation->durationMs,
        );
    }
}
