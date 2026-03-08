<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Message;

use Oronts\AssetPilotBundle\Enum\TriggerType;

readonly class BulkOrganizeMessage
{
    /** @param int[] $objectIds */
    public function __construct(
        public array $objectIds,
        public TriggerType $triggerType,
        public int $dispatchedAt = 0,
    ) {}
}
