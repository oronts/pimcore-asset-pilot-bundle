<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ReviewedSelectionResult;

interface ReviewedObjectOperationServiceInterface
{
    /**
     * @param list<int>            $objectIds
     * @param array<string, mixed> $selector
     */
    public function execute(
        string $kind,
        array $objectIds,
        array $selector,
        TriggerType $triggerType,
        bool $dryRun,
        bool $async,
        mixed $planToken = null,
        ?ActorContext $actor = null,
    ): ReviewedSelectionResult;
}
