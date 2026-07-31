<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Api\Serialization\ApiDateFormatterInterface;
use Oronts\AssetPilotBundle\Api\Serialization\OperationResponseAssembler;
use Oronts\AssetPilotBundle\Audit\AuditQueryInterface;
use Oronts\AssetPilotBundle\Controller\Api\Support\AuditRowDates;
use Oronts\AssetPilotBundle\Controller\Api\Support\DecodesJsonObject;
use Oronts\AssetPilotBundle\Controller\Api\Support\HandlesBulkIds;
use Oronts\AssetPilotBundle\Controller\Api\Support\ReadsRequestScalars;
use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Enum\BulkRunOutcomeKind;
use Oronts\AssetPilotBundle\Enum\OperationRunKind;
use Oronts\AssetPilotBundle\Enum\ReviewedSelectionError;
use Oronts\AssetPilotBundle\Enum\SingleRunOutcomeKind;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Exception\OrganizationRunDispatchException;
use Oronts\AssetPilotBundle\Exception\ReviewedSelectionException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Model\BulkObjectResult;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Model\ReviewedSelectionResult;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractorInterface;
use Oronts\AssetPilotBundle\Service\AssetOrganizerInterface;
use Oronts\AssetPilotBundle\Service\AssetReorganizerInterface;
use Oronts\AssetPilotBundle\Service\FailureReplayServiceInterface;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcherInterface;
use Oronts\AssetPilotBundle\Service\OrganizePlanFingerprint;
use Oronts\AssetPilotBundle\Service\OrganizeRunDispatchCoordinator;
use Oronts\AssetPilotBundle\Service\Query\UtcSinceCutoff;
use Oronts\AssetPilotBundle\Service\Query\VisibleObjectSelectorInterface;
use Oronts\AssetPilotBundle\Service\ReviewedObjectOperationServiceInterface;
use Oronts\AssetPilotBundle\Service\RunItemLease;
use Oronts\AssetPilotBundle\Service\SynchronousRunExecutor;
use Oronts\AssetPilotBundle\Support\BulkIds;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class OperationsController
{
    use DecodesJsonObject;
    use HandlesBulkIds;
    use ReadsRequestScalars;

    private readonly SynchronousRunExecutor $syncRunExecutor;

    public function __construct(
        protected readonly AssetOrganizerInterface $organizer,
        protected readonly OrganizeDispatcherInterface $organizeDispatcher,
        protected readonly AuditQueryInterface $auditLogger,
        protected readonly RuleEngineInterface $ruleEngine,
        protected readonly AssetFieldExtractorInterface $fieldExtractor,
        protected readonly FailureReplayServiceInterface $failureReplay,
        protected readonly AssetReorganizerInterface $reorganizer,
        protected readonly ElementAuthorizationInterface $authorization,
        protected readonly OperationRunStoreInterface $runs,
        protected readonly ApplyPlanServiceInterface $applyPlans,
        protected readonly OrganizePlanFingerprint $planFingerprints,
        protected readonly ReviewedObjectOperationServiceInterface $reviewedOperations,
        protected readonly LoggerInterface $logger,
        private readonly ApiDateFormatterInterface $dates,
        private readonly RunItemLease $runItemLease,
        private readonly OperationResponseAssembler $responses,
        private readonly OrganizeRunDispatchCoordinator $runCoordinator,
        private readonly VisibleObjectSelectorInterface $objectSelector,
        protected readonly int $defaultBatchSize = 50,
        protected readonly array $planConfiguration = [],
        ?SynchronousRunExecutor $syncRunExecutor = null,
    ) {
        $this->syncRunExecutor = $syncRunExecutor ?? new SynchronousRunExecutor($this->organizer, $this->runs, $this->runItemLease);
    }

    #[Route('/operations/reorganize', name: 'oronts_asset_pilot_operations_reorganize', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function reorganize(Request $request): JsonResponse
    {
        $data = $this->decodeJsonObject($request, true);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $folder = $this->requestString($data, 'folder', '');
        if ($folder instanceof JsonResponse) {
            return $folder;
        }
        $folder = trim($folder);
        if ($folder === '') {
            return new JsonResponse(['error' => 'A non-empty "folder" is required.'], Response::HTTP_BAD_REQUEST);
        }

        $options = $this->plannedExecutionOptions($data);
        if ($options instanceof JsonResponse) {
            return $options;
        }
        [$dryRun, $async] = $options;
        $limit = $this->requestOptionalPositiveInt($data, 'limit', BulkIds::MAX, null);
        if ($limit instanceof JsonResponse) {
            return $limit;
        }
        $selection = $this->reorganizer->selectFolder($folder, $limit ?? 0);

        return $this->reviewedSelectionResponse(
            fn (): ReviewedSelectionResult => $this->reviewedOperations->execute(
                OperationRunKind::Reorganize,
                $selection['objectIds'],
                ['folder' => $folder, 'limit' => $limit],
                TriggerType::Api,
                $dryRun,
                $async,
                $data['planToken'] ?? null,
                $this->authorization->currentActor(),
            ),
            ['assetsScanned' => $selection['assetCount'], 'ownerObjects' => count($selection['objectIds']), 'truncated' => $selection['truncated'] ?? false],
        );
    }

    #[Route('/operations/replay', name: 'oronts_asset_pilot_operations_replay', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function replay(Request $request): JsonResponse
    {
        $data = $this->decodeJsonObject($request, true);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $since = null;
        if (isset($data['since'])) {
            $rawSince = $this->requestString($data, 'since', null);
            if ($rawSince instanceof JsonResponse) {
                return $rawSince;
            }
            try {
                $since = UtcSinceCutoff::parse($rawSince);
            } catch (\Exception) {
                return new JsonResponse(['error' => 'The "since" value is not a valid date.'], Response::HTTP_BAD_REQUEST);
            }
        }
        $rule = $this->requestOptionalString($data, 'rule');
        if ($rule instanceof JsonResponse) {
            return $rule;
        }
        $class = $this->requestOptionalString($data, 'class');
        if ($class instanceof JsonResponse) {
            return $class;
        }
        $filters = array_filter([
            'since' => $since,
            'rule_name' => $rule,
            'object_class' => $class,
        ], static fn ($value): bool => $value !== null);

        $options = $this->plannedExecutionOptions($data);
        if ($options instanceof JsonResponse) {
            return $options;
        }
        [$dryRun, $async] = $options;
        $limit = $this->requestOptionalPositiveInt($data, 'limit', BulkIds::MAX, null);
        if ($limit instanceof JsonResponse) {
            return $limit;
        }
        $objectIds = $this->failureReplay->selectObjects($filters, $limit);

        return $this->reviewedSelectionResponse(
            fn (): ReviewedSelectionResult => $this->reviewedOperations->execute(
                OperationRunKind::Replay,
                $objectIds,
                ['filters' => $filters, 'limit' => $limit],
                TriggerType::Api,
                $dryRun,
                $async,
                $data['planToken'] ?? null,
                $this->authorization->currentActor(),
            ),
            ['candidates' => count($objectIds)],
        );
    }

    #[Route('/organize/explain', name: 'oronts_asset_pilot_organize_explain', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function explain(Request $request): JsonResponse
    {
        $resolved = $this->resolveObjectFromBody($request);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$object] = $resolved;

        $fieldInfos = $this->fieldExtractor->extract($object);
        $operations = $this->organizer->dryRun($object, TriggerType::Api);

        $allEvaluations = [];
        foreach ($fieldInfos as $fieldInfo) {
            foreach ($fieldInfo->assets as $asset) {
                $result = $this->ruleEngine->explain($object, $asset, $fieldInfo->fieldName, $fieldInfo->locale);

                foreach ($result['evaluations'] as $eval) {
                    $allEvaluations[] = [
                        'assetId' => $asset->getId(),
                        'assetPath' => $asset->getRealFullPath(),
                        'fieldName' => $fieldInfo->fieldName,
                        'locale' => $fieldInfo->locale,
                        'ruleName' => $eval->ruleName,
                        'matched' => $eval->matched,
                        'rejectionReason' => $eval->rejectionReason,
                        'conditionExpression' => $eval->conditionExpression,
                        'conditionResult' => $eval->conditionResult,
                        'conditionError' => $eval->conditionError,
                        'filterDetails' => $eval->filterDetails,
                        'resolvedPath' => $eval->resolvedPath,
                        'priority' => $eval->priority,
                        'enabled' => $eval->enabled,
                    ];
                }
            }
        }

        return new JsonResponse([
            'objectId' => $object->getId(),
            'operations' => array_map(static fn ($op) => [
                'assetId' => $op->assetId,
                'sourcePath' => $op->sourcePath,
                'targetPath' => $op->targetPath,
                'ruleName' => $op->ruleName,
                'status' => $op->status->value,
            ], $operations),
            'evaluations' => $allEvaluations,
        ]);
    }

    #[Route('/organize', name: 'oronts_asset_pilot_organize', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function organize(Request $request): JsonResponse
    {
        $resolved = $this->resolveObjectFromBody($request);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$object, $data] = $resolved;

        $objectId = $object->getId();
        $dryRun = $this->requestBool($data, 'dryRun', false);
        if ($dryRun instanceof JsonResponse) {
            return $dryRun;
        }
        $async = $this->requestBool($data, 'async', false);
        if ($async instanceof JsonResponse) {
            return $async;
        }
        $actor = $this->authorization->currentActor();

        if (!$dryRun && !$this->authorization->isAllowed($object, 'publish')) {
            return new JsonResponse(['error' => 'Object mutation is not permitted.'], Response::HTTP_FORBIDDEN);
        }

        $this->logger->info('Asset Pilot API: organize triggered for object {id}', [
            'id' => $objectId,
            'dryRun' => $dryRun,
            'async' => $async,
        ]);
        if (!$dryRun && !$this->hasPlanToken($data['planToken'] ?? null)) {
            return $this->missingPlanTokenResponse();
        }

        $preview = $this->previewObjects([$object]);
        $plan = $this->organizePlan(
            OperationRunKind::Organize->value,
            $actor,
            ['objectId' => (int) $objectId, 'trigger' => TriggerType::Api->value],
            $preview['targets'],
        );
        if ($dryRun) {
            return new JsonResponse([
                'dryRun' => true,
                'planToken' => $this->applyPlans->issue($plan),
                'operations' => array_map($this->responses->previewOperation(...), $preview['operations']),
            ]);
        }

        $preflightError = $this->organizer->preflightApply($preview['operations']);
        if ($preflightError !== null) {
            return new JsonResponse(['error' => $preflightError], Response::HTTP_FORBIDDEN);
        }

        $planError = $this->claimPlan($data['planToken'] ?? null, $plan);
        if ($planError !== null) {
            return $planError;
        }
        $expectedFingerprint = $preview['fingerprints'][(int) $objectId];

        if ($async) {
            try {
                $queued = $this->runCoordinator->queueOrganization((int) $objectId, TriggerType::Api, $actor, $expectedFingerprint);
            } catch (OrganizationRunDispatchException $e) {
                return new JsonResponse(['error' => 'Organization could not be queued.', 'runId' => $e->runId], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            return new JsonResponse($this->responses->queuedSingle($queued->runId), Response::HTTP_ACCEPTED);
        }

        $runId = $this->organizeDispatcher->createRun(
            [(int) $objectId],
            TriggerType::Api,
            $actor,
            [(int) $objectId => $expectedFingerprint],
        );
        $this->runs->start($runId);
        $outcome = $this->syncRunExecutor->executeSingle($runId, $object, TriggerType::Api, $expectedFingerprint);
        if ($outcome->kind === SingleRunOutcomeKind::Failed) {
            $this->logger->error('Asset Pilot API: organization failed for object {id}', ['id' => $objectId, 'exception' => $outcome->cause]);
        }

        return match ($outcome->kind) {
            SingleRunOutcomeKind::Completed => new JsonResponse($this->responses->singleRunResult($runId, $outcome->results)),
            SingleRunOutcomeKind::ClaimConflict => new JsonResponse([
                'error' => 'The operation could not be claimed for execution; it may have been cancelled or is already running.',
                'runId' => $runId,
            ], Response::HTTP_CONFLICT),
            SingleRunOutcomeKind::LeaseLost => new JsonResponse([
                'error' => 'The operation ran but its result could not be durably recorded because the run-item lease was lost.',
                'runId' => $runId,
            ], Response::HTTP_CONFLICT),
            SingleRunOutcomeKind::Stale => new JsonResponse([
                'error' => 'Object changed after preview. Run a new dry-run before applying.',
                'runId' => $runId,
            ], Response::HTTP_CONFLICT),
            SingleRunOutcomeKind::Failed => new JsonResponse(['error' => 'Organization failed.', 'runId' => $runId], Response::HTTP_INTERNAL_SERVER_ERROR),
        };
    }

    #[Route('/organize/bulk', name: 'oronts_asset_pilot_organize_bulk', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function organizeBulk(Request $request): JsonResponse
    {
        $data = $this->decodeJsonObject($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $className = $this->requestOptionalString($data, 'className');
        if ($className instanceof JsonResponse) {
            return $className;
        }
        if ($className !== null && trim($className) === '') {
            $className = null;
        }
        $rawObjectIds = $data['objectIds'] ?? [];
        $async = $this->requestBool($data, 'async', true);
        if ($async instanceof JsonResponse) {
            return $async;
        }
        $dryRun = $this->requestBool($data, 'dryRun', false);
        if ($dryRun instanceof JsonResponse) {
            return $dryRun;
        }
        // Validate batchSize up front (only async dispatch uses it) so a malformed value is rejected
        // before the single-use plan token is claimed, not after.
        $batchSize = $this->defaultBatchSize;
        if ($async) {
            $batchSize = $this->requestOptionalPositiveInt($data, 'batchSize', BulkIds::MAX, $this->defaultBatchSize);
            if ($batchSize instanceof JsonResponse) {
                return $batchSize;
            }
        }
        $actor = $this->authorization->currentActor();

        if ($className === null && empty($rawObjectIds)) {
            return new JsonResponse(['error' => 'className or objectIds required'], Response::HTTP_BAD_REQUEST);
        }

        $objectIds = [];
        if (!empty($rawObjectIds)) {
            $objectIds = $this->validatedBulkIds($rawObjectIds, 'objectIds');
            if ($objectIds instanceof JsonResponse) {
                return $objectIds;
            }
        }

        // Resolve object IDs from a class name under a bounded authorized scan: a whole-catalog
        // className must never load and ACL-check the entire class, nor silently organize a partial
        // selection. Both "more objects than the cap" and "the class could not be resolved within the
        // raw candidate budget" are a 400 asking for explicit objectIds; a signed plan is only issued
        // for a fully-resolved selection within the cap.
        if (empty($objectIds) && $className !== null) {
            $resolved = $this->objectSelector->resolveIds((string) $className);
            $objectIds = $resolved['ids'];

            if (count($objectIds) > BulkIds::MAX) {
                return new JsonResponse(
                    ['error' => sprintf('Class "%s" resolves to more than %d objects; narrow the selection or pass objectIds.', $className, BulkIds::MAX)],
                    Response::HTTP_BAD_REQUEST,
                );
            }
            if ($resolved['truncated']) {
                return new JsonResponse(
                    ['error' => sprintf('Class "%s" could not be resolved within the scan budget; pass explicit objectIds or narrow the selection.', $className)],
                    Response::HTTP_BAD_REQUEST,
                );
            }
        }

        if (empty($objectIds)) {
            return new JsonResponse(['error' => 'No objects found'], Response::HTTP_NOT_FOUND);
        }

        $objects = [];
        foreach ($objectIds as $objectId) {
            $object = $this->loadObject($objectId);
            if ($object === null || !$this->authorization->isAllowed($object, 'view')) {
                return new JsonResponse(['error' => 'Object access is not permitted.', 'objectId' => $objectId], Response::HTTP_FORBIDDEN);
            }
            if (!$dryRun && !$this->authorization->isAllowed($object, 'publish')) {
                return new JsonResponse(['error' => 'Object mutation is not permitted.', 'objectId' => $objectId], Response::HTTP_FORBIDDEN);
            }
            $objects[] = $object;
        }

        $this->logger->info('Asset Pilot API: bulk organize triggered for {count} objects', [
            'count' => count($objectIds),
        ]);
        if (!$dryRun && !$this->hasPlanToken($data['planToken'] ?? null)) {
            return $this->missingPlanTokenResponse();
        }

        $planObjectIds = $objectIds;
        sort($planObjectIds);
        $preview = $this->previewObjects($objects);
        $plan = $this->organizePlan(
            'organize-bulk',
            $actor,
            [
                'className' => $className === null ? null : (string) $className,
                'objectIds' => $planObjectIds,
                'trigger' => TriggerType::Api->value,
            ],
            $preview['targets'],
        );

        if ($dryRun) {

            return new JsonResponse([
                'dryRun' => true,
                'planToken' => $this->applyPlans->issue($plan),
                'objectCount' => count($objectIds),
                'operations' => array_map($this->responses->previewOperation(...), $preview['operations']),
            ]);
        }

        $preflightError = $this->organizer->preflightApply($preview['operations']);
        if ($preflightError !== null) {
            return new JsonResponse(['error' => $preflightError], Response::HTTP_FORBIDDEN);
        }

        $planError = $this->claimPlan($data['planToken'] ?? null, $plan);
        if ($planError !== null) {
            return $planError;
        }

        if ($async) {
            try {
                $queued = $this->runCoordinator->queueBulkOrganization($objectIds, TriggerType::Api, $actor, $preview['fingerprints'], $batchSize);
            } catch (OrganizationRunDispatchException $e) {
                return new JsonResponse(['error' => 'Bulk organization could not be queued.', 'runId' => $e->runId], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            return new JsonResponse(
                $this->responses->queuedBulk($queued->runId, $queued->objectCount, $queued->batchCount),
                Response::HTTP_ACCEPTED,
            );
        }

        $runId = $this->organizeDispatcher->createRun($objectIds, TriggerType::Api, $actor, $preview['fingerprints']);
        $this->runs->start($runId);
        $outcome = $this->syncRunExecutor->executeBulk($runId, $objectIds, TriggerType::Api, $preview['fingerprints']);
        if ($outcome->kind === BulkRunOutcomeKind::Failed) {
            $this->logger->error('Asset Pilot API: bulk organization failed', ['exception' => $outcome->cause]);
        }

        return match ($outcome->kind) {
            BulkRunOutcomeKind::Completed => new JsonResponse($this->responses->bulkRunResult($runId, $outcome->report ?? throw new \LogicException('Completed bulk run outcome must carry a report.'))),
            BulkRunOutcomeKind::OwnershipLost => new JsonResponse([
                'error' => 'The bulk operation lost ownership of a run item to a concurrent attempt; that attempt records the item.',
                'runId' => $runId,
            ], Response::HTTP_CONFLICT),
            BulkRunOutcomeKind::Failed => new JsonResponse(['error' => 'Bulk organization failed.', 'runId' => $runId], Response::HTTP_INTERNAL_SERVER_ERROR),
        };
    }

    #[Route('/operations/bulk-preview', name: 'oronts_asset_pilot_bulk_preview', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function bulkPreview(Request $request): JsonResponse
    {
        $data = $this->decodeJsonObject($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $className = $this->requestOptionalString($data, 'className');
        if ($className instanceof JsonResponse) {
            return $className;
        }
        if ($className === null || trim($className) === '') {
            return new JsonResponse(['error' => 'className is required'], Response::HTTP_BAD_REQUEST);
        }

        $page = $this->requestOptionalPositiveInt($data, 'page', null, 1);
        if ($page instanceof JsonResponse) {
            return $page;
        }
        $limit = $this->requestOptionalPositiveInt($data, 'limit', 200, 50);
        if ($limit instanceof JsonResponse) {
            return $limit;
        }
        if ($page - 1 > intdiv(\PHP_INT_MAX, $limit)) {
            return new JsonResponse(['error' => 'page is out of range'], Response::HTTP_BAD_REQUEST);
        }

        $selection = $this->objectSelector->page((string) $className, ($page - 1) * $limit, $limit);

        return new JsonResponse([
            'objects' => $selection['objects'],
            'total' => null,
            'page' => $page,
            'pages' => null,
            'hasMore' => $selection['hasMore'],
            'truncated' => $selection['truncated'],
        ]);
    }

    #[Route('/operations/status', name: 'oronts_asset_pilot_operations_status', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function status(): JsonResponse
    {
        $stats = $this->auditLogger->getStats();
        $recent = array_map(
            fn (array $row): array => AuditRowDates::normalize($row, $this->dates),
            $this->auditLogger->getRecent(20),
        );

        return new JsonResponse([
            'stats' => $stats,
            'recentOperations' => $recent,
        ]);
    }

    /** @return array{0: bool, 1: bool}|JsonResponse */
    private function plannedExecutionOptions(array $data): array|JsonResponse
    {
        $dryRun = $data['dryRun'] ?? true;
        $async = $data['async'] ?? false;
        if (!is_bool($dryRun)) {
            return new JsonResponse(['error' => 'dryRun must be a boolean.'], Response::HTTP_BAD_REQUEST);
        }
        if (!is_bool($async)) {
            return new JsonResponse(['error' => 'async must be a boolean.'], Response::HTTP_BAD_REQUEST);
        }

        return [$dryRun, $async];
    }

    /**
     * @param \Closure(): ReviewedSelectionResult $execute
     * @param array<string, mixed>                $summary
     */
    private function reviewedSelectionResponse(\Closure $execute, array $summary): JsonResponse
    {
        try {
            $result = $execute();
        } catch (ReviewedSelectionException $e) {
            $payload = ['error' => $e->getMessage()];
            if ($e->objectId !== null) {
                $payload['objectId'] = $e->objectId;
            }
            if ($e->runId !== null) {
                $payload['runId'] = $e->runId;
            }

            return new JsonResponse($payload, $this->reviewedSelectionErrorStatus($e->error));
        }

        $payload = [
            ...$summary,
            'dryRun' => $result->dryRun,
            'planToken' => $result->planToken,
            'runId' => $result->runId,
            'statusUrl' => $result->runId === null ? null : $this->responses->statusUrl($result->runId),
            'objectCount' => $result->objectCount,
            'organized' => $result->organized,
            'dispatched' => $result->dispatched,
            'skipped' => $result->skipped,
            'failed' => $result->failed,
        ];
        if ($result->runStatus !== null) {
            $payload['runStatus'] = $result->runStatus->value;
        }
        if ($result->dryRun) {
            $payload['operations'] = array_map($this->responses->previewOperation(...), $result->operations);
        }
        if ($result->objectResults !== []) {
            $payload['objectResults'] = array_map(static fn (BulkObjectResult $item): array => [
                'objectId' => $item->objectId,
                'status' => $item->status->value,
                'reason' => $item->reason,
                'operationCount' => $item->operationCount,
            ], $result->objectResults);
        }
        if ($result->observerWarnings !== []) {
            $payload['observerWarnings'] = $result->observerWarnings;
        }

        return new JsonResponse($payload, $result->dispatched > 0 ? Response::HTTP_ACCEPTED : Response::HTTP_OK);
    }

    private function reviewedSelectionErrorStatus(ReviewedSelectionError $error): int
    {
        return match ($error) {
            ReviewedSelectionError::SelectionTooLarge,
            ReviewedSelectionError::MissingPlanToken,
            ReviewedSelectionError::MalformedPlanToken => Response::HTTP_BAD_REQUEST,
            ReviewedSelectionError::MutationForbidden,
            ReviewedSelectionError::PreflightFailed => Response::HTTP_FORBIDDEN,
            ReviewedSelectionError::StalePlan,
            ReviewedSelectionError::OwnershipLost => Response::HTTP_CONFLICT,
            ReviewedSelectionError::ExecutionFailed => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    /**
     * Decode the JSON body and resolve the required `objectId` to a loaded object.
     *
     * @return array{0: AbstractObject, 1: array<string, mixed>}|JsonResponse the loaded object plus
     *                                                                         the decoded body, or the error response to return
     */
    protected function resolveObjectFromBody(Request $request): array|JsonResponse
    {
        $data = $this->decodeJsonObject($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $objectId = $this->requestPositiveInt($data, 'objectId', null);
        if ($objectId instanceof JsonResponse) {
            return $objectId;
        }

        $object = $this->loadObject($objectId);
        if ($object === null) {
            return new JsonResponse(['error' => 'Object not found'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->authorization->isAllowed($object, 'view')) {
            return new JsonResponse(['error' => 'Object access is not permitted.'], Response::HTTP_FORBIDDEN);
        }

        return [$object, $data];
    }

    protected function loadObject(int $id): ?AbstractObject
    {
        return AbstractObject::getById($id);
    }

    /**
     * @param list<AbstractObject> $objects
     *
     * @return array{operations: list<MoveOperation>, targets: list<ApplyPlanTarget>, fingerprints: array<int, string>}
     */
    private function previewObjects(array $objects): array
    {
        $operations = [];
        $targets = [];
        $fingerprints = [];
        foreach ($objects as $object) {
            $objectOperations = $this->organizer->dryRun($object, TriggerType::Api);
            $objectId = (int) $object->getId();
            $fingerprint = $this->planFingerprints->forOperations($object, $objectOperations);
            $fingerprints[$objectId] = $fingerprint;
            $targets[] = new ApplyPlanTarget('object:' . $objectId, $fingerprint);
            array_push($operations, ...$objectOperations);
        }

        return [
            'operations' => $operations,
            'targets' => $targets,
            'fingerprints' => $fingerprints,
        ];
    }

    /**
     * @param array<string, mixed>      $request
     * @param list<ApplyPlanTarget> $targets
     */
    private function organizePlan(string $kind, ActorContext $actor, array $request, array $targets): ApplyPlan
    {
        return new ApplyPlan($kind, $actor, $request, $this->planConfiguration, $targets);
    }

    private function claimPlan(mixed $token, ApplyPlan $plan): ?JsonResponse
    {
        if (!$this->hasPlanToken($token)) {
            return $this->missingPlanTokenResponse();
        }

        return match ($this->applyPlans->claim($token, $plan)) {
            ApplyPlanStatus::Claimed => null,
            ApplyPlanStatus::Malformed => new JsonResponse(
                ['error' => 'The planToken is malformed or has an invalid signature.'],
                Response::HTTP_BAD_REQUEST,
            ),
            ApplyPlanStatus::Stale, ApplyPlanStatus::AlreadyClaimed, ApplyPlanStatus::Valid => new JsonResponse(
                ['error' => 'The preview plan is stale or already applied. Run a new dry-run preview.'],
                Response::HTTP_CONFLICT,
            ),
        };
    }

    private function hasPlanToken(mixed $token): bool
    {
        return is_string($token) && trim($token) !== '';
    }

    private function missingPlanTokenResponse(): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'A planToken from a matching dry-run preview is required.'],
            Response::HTTP_BAD_REQUEST,
        );
    }

}
