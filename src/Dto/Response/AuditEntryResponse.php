<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Dto\Response;

readonly class AuditEntryResponse
{
    public function __construct(
        public int $id,
        public int $assetId,
        public string $assetPathFrom,
        public string $assetPathTo,
        public int $objectId,
        public string $objectClass,
        public string $ruleName,
        public string $triggerType,
        public string $status,
        public ?string $errorMessage,
        public ?int $durationMs,
        public ?int $userId,
        public string $createdAt,
    ) {}
}
