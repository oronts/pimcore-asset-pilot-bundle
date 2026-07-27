<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Api\Serialization;

use Oronts\AssetPilotBundle\Model\BulkObjectResult;
use Oronts\AssetPilotBundle\Model\BulkOrganizeReport;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Model\OperationResult;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Assembles the organize-family HTTP response payloads from domain results, keeping response shaping out
 * of the controller. Shared by the organize, bulk, queued, dry-run preview, and reviewed-selection paths.
 */
final readonly class OperationResponseAssembler
{
    public function __construct(private UrlGeneratorInterface $urlGenerator) {}

    /** @return array<string, int|string|null> */
    public function previewOperation(MoveOperation $operation): array
    {
        return [
            'assetId' => $operation->assetId,
            'sourcePath' => $operation->sourcePath,
            'targetPath' => $operation->targetPath,
            'ruleName' => $operation->ruleName,
            'objectId' => $operation->objectId,
            'objectClass' => $operation->objectClass,
            'status' => $operation->status->value,
        ];
    }

    /**
     * @param list<OperationResult> $results
     *
     * @return array{runId: string, results: list<array<string, mixed>>}
     */
    public function singleRunResult(string $runId, array $results): array
    {
        return [
            'runId' => $runId,
            'results' => array_map(static fn (OperationResult $result): array => [
                'status' => $result->status->value,
                'message' => $result->message,
                'operation' => $result->operation === null ? null : [
                    'assetId' => $result->operation->assetId,
                    'sourcePath' => $result->operation->sourcePath,
                    'targetPath' => $result->operation->targetPath,
                    'ruleName' => $result->operation->ruleName,
                ],
            ], $results),
        ];
    }

    /** @return array<string, mixed> */
    public function bulkRunResult(string $runId, BulkOrganizeReport $report): array
    {
        return [
            'runId' => $runId,
            'objectCount' => $report->attemptedCount(),
            'resultCount' => count($report->results),
            'objectCounts' => [
                'attempted' => $report->attemptedCount(),
                'succeeded' => $report->succeededCount(),
                'skipped' => $report->skippedCount(),
                'failed' => $report->failedCount(),
            ],
            'objectResults' => array_map(static fn (BulkObjectResult $result): array => [
                'objectId' => $result->objectId,
                'status' => $result->status->value,
                'reason' => $result->reason,
                'operationCount' => $result->operationCount,
            ], $report->objectResults),
            'observerWarnings' => $report->observerWarnings,
        ];
    }

    /** @return array{message: string, runId: string, statusUrl: string} */
    public function queuedSingle(string $runId): array
    {
        return [
            'message' => 'Organization queued',
            'runId' => $runId,
            'statusUrl' => $this->statusUrl($runId),
        ];
    }

    /** @return array{message: string, runId: string, statusUrl: string, objectCount: int, batchCount: int} */
    public function queuedBulk(string $runId, int $objectCount, int $batchCount): array
    {
        return [
            'message' => 'Bulk organization queued',
            'runId' => $runId,
            'statusUrl' => $this->statusUrl($runId),
            'objectCount' => $objectCount,
            'batchCount' => $batchCount,
        ];
    }

    public function statusUrl(string $runId): string
    {
        return $this->urlGenerator->generate('oronts_asset_pilot_operation_run_get', ['id' => $runId]);
    }
}
