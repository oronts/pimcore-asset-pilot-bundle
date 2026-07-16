<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Merge\CopyDisposition;

final readonly class OperationRunExecution
{
    /** @param list<CopyDisposition> $dispositions */
    public function __construct(
        public OperationRunStatus $status,
        public bool $asynchronous,
        public array $dispositions = [],
    ) {}
}
