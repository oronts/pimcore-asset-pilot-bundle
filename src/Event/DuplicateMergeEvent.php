<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Event;

use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
use Oronts\AssetPilotBundle\Merge\CopyDisposition;
use Oronts\AssetPilotBundle\Merge\RepointReport;
use Symfony\Contracts\EventDispatcher\Event;

class DuplicateMergeEvent extends Event
{
    public function __construct(
        public readonly string $runId,
        public readonly string $checksum,
        public readonly int $canonicalId,
        public readonly string $strategy,
        public readonly CopyDisposition $disposition,
        public readonly RepointReport $repointReport,
        public readonly OperationRunItemStatus $itemStatus,
    ) {}
}
