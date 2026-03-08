<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Dto\Response;

readonly class OperationResponse
{
    public function __construct(
        public int $assetId,
        public string $sourcePath,
        public string $targetPath,
        public int $objectId,
        public string $objectClass,
        public string $ruleName,
        public string $status,
        public string $triggerType,
        public ?string $errorMessage,
        public ?int $durationMs,
        public string $createdAt,
    ) {}
}
