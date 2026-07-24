<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\BulkObjectStatus;

readonly class BulkOrganizeReport
{
    /**
     * @param list<OperationResult>  $results
     * @param list<BulkObjectResult> $objectResults
     * @param list<string>           $observerWarnings
     */
    public function __construct(
        public array $results,
        public array $objectResults,
        public array $observerWarnings = [],
    ) {}

    public function attemptedCount(): int
    {
        return count($this->objectResults);
    }

    public function succeededCount(): int
    {
        return $this->count(BulkObjectStatus::Succeeded);
    }

    public function skippedCount(): int
    {
        return $this->count(BulkObjectStatus::Skipped);
    }

    public function failedCount(): int
    {
        return $this->count(BulkObjectStatus::Failed);
    }

    private function count(BulkObjectStatus $status): int
    {
        return count(array_filter(
            $this->objectResults,
            static fn (BulkObjectResult $result): bool => $result->status === $status,
        ));
    }
}
