<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\BulkObjectStatus;

readonly class BulkObjectResult
{
    public function __construct(
        public int $objectId,
        public BulkObjectStatus $status,
        public ?string $reason = null,
        public int $operationCount = 0,
    ) {}
}
