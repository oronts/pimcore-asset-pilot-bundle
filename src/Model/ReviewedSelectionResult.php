<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\OperationRunStatus;

readonly class ReviewedSelectionResult
{
    /**
     * @param list<MoveOperation>    $operations
     * @param list<BulkObjectResult> $objectResults
     * @param list<string>           $observerWarnings
     */
    public function __construct(
        public bool $dryRun,
        public ?string $planToken,
        public ?string $runId,
        public ?OperationRunStatus $runStatus,
        public int $objectCount,
        public int $organized,
        public int $dispatched,
        public int $skipped,
        public int $failed,
        public array $operations = [],
        public array $objectResults = [],
        public array $observerWarnings = [],
    ) {}
}
