<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Event;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\OperationResult;
use Symfony\Contracts\EventDispatcher\Event;

class BulkOrganizeEvent extends Event
{
    /**
     * @param int[]              $objectIds the objects in this bulk run
     * @param OperationResult[]  $results   move results (empty on BULK_STARTED, populated on BULK_COMPLETED)
     */
    public function __construct(
        public readonly array $objectIds,
        public readonly TriggerType $triggerType,
        public readonly array $results = [],
    ) {}
}
