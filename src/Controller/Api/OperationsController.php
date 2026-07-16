<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Audit\AuditLoggerInterface;
use Oronts\AssetPilotBundle\Controller\Api\Support\DecodesJsonObject;
use Oronts\AssetPilotBundle\Controller\Api\Support\HandlesBulkIds;
use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Enum\BulkObjectStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\ReviewedSelectionError;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Exception\ReviewedSelectionException;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Model\BulkObjectResult;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Model\ReviewedSelectionResult;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractorInterface;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\AssetReorganizer;
use Oronts\AssetPilotBundle\Service\FailureReplayService;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcher;
use Oronts\AssetPilotBundle\Service\OrganizePlanFingerprint;
use Oronts\AssetPilotBundle\Service\ReviewedObjectOperationServiceInterface;
use Oronts\AssetPilotBundle\Support\BulkIds;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class OperationsController
{
    use DecodesJsonObject;
    use HandlesBulkIds;

    public function __construct(
        protected readonly AssetOrganizer $organizer,
        protected readonly OrganizeDispatcher $organizeDispatcher,
        protected readonly AuditLoggerInterface $auditLogger,
        protected readonly RuleEngineInterface $ruleEngine,
        protected readonly AssetFieldExtractorInterface $fieldExtractor,
        protected readonly FailureReplayService $failureReplay,
        protected readonly AssetReorganizer $reorganizer,
        protected readonly ElementAuthorization $authorization,
        protected readonly OperationRunStoreInterface $runs,
        protected readonly ApplyPlanServiceInterface $applyPlans,
        protected readonly OrganizePlanFingerprint $planFingerprints,
        protected readonly ReviewedObjectOperationServiceInterface $reviewedOperations,
        protected readonly UrlGeneratorInterface $urlGenerator,
        protected readonly LoggerInterface $logger,
        protected readonly int $defaultBatchSize = 50,
        protected readonly array $planConfiguration = [],
    ) {}

    #[Route('/operations/reorganize', name: 'oronts_asset_pilot_operations_reorganize', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function reorganize(Request $request): JsonResponse
    {
        $data = $this->decodeJsonObject($request, true);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $folder = trim((string) ($data['folder'] ?? ''));
        if ($folder === '') {
            return new JsonResponse(['error' => 'A non-empty "folder" is required.'], Response::HTTP_BAD_REQUEST);
        }

        $options = $this->plannedExecutionOptions($data);
        if ($options instanceof JsonResponse) {
            return $options;
        }
        [$dryRun, $async] = $options;
        $limit = isset($data['limit']) ? max(1, (int) $data['limit']) : 0;
        $selection = $this->reorganizer->selectFolder($folder, $limit);

        return $this->reviewedSelectionResponse(
            fn (): ReviewedSelectionResult => $this->reviewedOperations->execute(
                'reorganize',
                $selection['objectIds'],
                ['folder' => $folder, 'limit' => $limit],
                TriggerType::Api,
                $dryRun,
                $async,
                $data['planToken'] ?? null,
                $this->authorization->currentActor(),
            ),
            ['assetsScanned' => $selection['assetCount'], 'ownerObjects' => count($selection['objectIds'])],
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

        $since = isset($data['since']) ? strtotime((string) $data['since']) : null;
        if ($since === false) {
            return new JsonResponse(['error' => 'The "since" value is not a valid date.'], Response::HTTP_BAD_REQUEST);
        }
        $filters = array_filter([
            'since' => is_int($since) ? date('Y-m-d H:i:s', $since) : null,
            'rule_name' => $data['rule'] ?? null,
            'object_class' => $data['class'] ?? null,
        ], static fn ($value): bool => $value !== null);

        $options = $this->plannedExecutionOptions($data);
        if ($options instanceof JsonResponse) {
            return $options;
        }
        [$dryRun, $async] = $options;
        $limit = isset($data['limit']) ? max(1, (int) $data['limit']) : null;
        $objectIds = $this->failureReplay->selectObjects($filters, $limit);

        return $this->reviewedSelectionResponse(
            fn (): ReviewedSelectionResult => $this->reviewedOperations->execute(
                'replay',
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
        $dryRun = (bool) ($data['dryRun'] ?? false);
        $async = (bool) ($data['async'] ?? false);
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
            'organize',
            $actor,
            ['objectId' => (int) $objectId, 'trigger' => TriggerType::Api->value],
            $preview['targets'],
        );
        if ($dryRun) {
            return new JsonResponse([
                'dryRun' => true,
                'planToken' => $this->applyPlans->issue($plan),
                'operations' => array_map($this->operationPreview(...), $preview['operations']),
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
            return $this->queueOrganization((int) $objectId, $actor, $expectedFingerprint);
        }

        $runId = $this->organizeDispatcher->createRun(
            [(int) $objectId],
            TriggerType::Api,
            $actor,
            [(int) $objectId => $expectedFingerprint],
        );
        $this->runs->start($runId);
        $this->runs->startItem($runId, $this->runItemKey((int) $objectId));
        try {
            $results = $this->organizer->organize(
                $object,
                TriggerType::Api,
                expectedFingerprint: $expectedFingerprint,
            );
            $this->runs->completeItem(
                $runId,
                $this->runItemKey((int) $objectId),
                $this->operationItemStatus($results),
                ['operationCount' => count($results)],
            );
            $this->runs->finish($runId);
        } catch (StaleApplyPlanException) {
            $this->runs->completeItem(
                $runId,
                $this->runItemKey((int) $objectId),
                OperationRunItemStatus::Skipped,
                error: 'Object changed after preview; the immutable plan was not applied.',
            );
            $this->runs->finish($runId);

            return new JsonResponse([
                'error' => 'Object changed after preview. Run a new dry-run before applying.',
                'runId' => $runId,
            ], Response::HTTP_CONFLICT);
        } catch (\Throwable $e) {
            $this->runs->completeItem($runId, $this->runItemKey((int) $objectId), OperationRunItemStatus::Failed, error: 'Organization failed.');
            $this->runs->fail($runId, 'Organization failed.');
            $this->logger->error('Asset Pilot API: organization failed for object {id}', ['id' => $objectId, 'exception' => $e]);

            return new JsonResponse(['error' => 'Organization failed.', 'runId' => $runId], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'runId' => $runId,
            'results' => array_map(static fn ($r) => [
                'status' => $r->status->value,
                'message' => $r->message,
                'operation' => $r->operation ? [
                    'assetId' => $r->operation->assetId,
                    'sourcePath' => $r->operation->sourcePath,
                    'targetPath' => $r->operation->targetPath,
                    'ruleName' => $r->operation->ruleName,
                ] : null,
            ], $results),
        ]);
    }

    #[Route('/organize/bulk', name: 'oronts_asset_pilot_organize_bulk', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function organizeBulk(Request $request): JsonResponse
    {
        $data = $this->decodeJsonObject($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $className = $data['className'] ?? null;
        $rawObjectIds = $data['objectIds'] ?? [];
        $async = (bool) ($data['async'] ?? true);
        $dryRun = (bool) ($data['dryRun'] ?? false);
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

        // Resolve object IDs from class name if not provided directly. Cap the listing at the same
        // per-request limit the objectIds path enforces, so a whole-catalog className cannot queue or
        // run an unbounded batch through the move pipeline. Over the cap is a 400, not a silent
        // truncation: the caller narrows the selection or paginates via bulk-preview.
        if (empty($objectIds) && $className !== null) {
            foreach ($this->objectsForClass((string) $className) as $object) {
                if (!$this->authorization->isAllowed($object, 'view')) {
                    continue;
                }
                $objectIds[] = (int) $object->getId();
                if (count($objectIds) > BulkIds::MAX) {
                    break;
                }
            }

            if (count($objectIds) > BulkIds::MAX) {
                return new JsonResponse(
                    ['error' => sprintf('Class "%s" resolves to more than %d objects; narrow the selection or pass objectIds.', $className, BulkIds::MAX)],
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
                'operations' => array_map($this->operationPreview(...), $preview['operations']),
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
            $batchSize = max(1, (int) ($data['batchSize'] ?? $this->defaultBatchSize));
            return $this->queueBulkOrganization($objectIds, $actor, $preview['fingerprints'], $batchSize);
        }

        $runId = $this->organizeDispatcher->createRun($objectIds, TriggerType::Api, $actor, $preview['fingerprints']);
        $this->runs->start($runId);
        try {
            $report = $this->organizer->organizeBulkDetailed(
                $objectIds,
                TriggerType::Api,
                shouldCancel: fn (): bool => $this->runs->isCancellationRequested($runId),
                beforeObject: fn (int $objectId): bool => $this->runs->startItem($runId, $this->runItemKey($objectId)),
                expectedFingerprints: $preview['fingerprints'],
            );
            foreach ($report->objectResults as $result) {
                $this->runs->completeItem(
                    $runId,
                    $this->runItemKey($result->objectId),
                    $this->bulkItemStatus($result->status),
                    ['operationCount' => $result->operationCount],
                    $result->reason,
                );
            }
            $this->runs->finish($runId);
        } catch (\Throwable $e) {
            foreach ($objectIds as $objectId) {
                $this->runs->completeItem($runId, $this->runItemKey($objectId), OperationRunItemStatus::Failed, error: 'Bulk organization failed.');
            }
            $this->runs->fail($runId, 'Bulk organization failed.');
            $this->logger->error('Asset Pilot API: bulk organization failed', ['exception' => $e]);

            return new JsonResponse(['error' => 'Bulk organization failed.', 'runId' => $runId], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
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
        ]);
    }

    #[Route('/operations/bulk-preview', name: 'oronts_asset_pilot_bulk_preview', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function bulkPreview(Request $request): JsonResponse
    {
        $data = $this->decodeJsonObject($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $className = $data['className'] ?? null;
        if ($className === null) {
            return new JsonResponse(['error' => 'className is required'], Response::HTTP_BAD_REQUEST);
        }

        $page = max(1, (int) ($data['page'] ?? 1));
        $limit = min(200, max(1, (int) ($data['limit'] ?? 50)));
        $visibleOffset = ($page - 1) * $limit;
        $total = 0;
        $objects = [];
        foreach ($this->objectsForClass((string) $className) as $obj) {
            if (!$this->authorization->isAllowed($obj, 'view')) {
                continue;
            }
            if ($total++ < $visibleOffset || count($objects) >= $limit) {
                continue;
            }
            $objects[] = [
                'id' => $obj->getId(),
                'key' => $obj->getKey(),
                'className' => $obj instanceof Concrete ? $obj->getClassName() : null,
            ];
        }

        return new JsonResponse([
            'objects' => $objects,
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / $limit),
        ]);
    }

    #[Route('/operations/status', name: 'oronts_asset_pilot_operations_status', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function status(): JsonResponse
    {
        $stats = $this->auditLogger->getStats();
        $recent = $this->auditLogger->getRecent(20);

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
            'statusUrl' => $result->runId === null ? null : $this->runStatusUrl($result->runId),
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
            $payload['operations'] = array_map($this->operationPreview(...), $result->operations);
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
            ReviewedSelectionError::StalePlan => Response::HTTP_CONFLICT,
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

        $objectId = $data['objectId'] ?? null;
        if ($objectId === null) {
            return new JsonResponse(['error' => 'objectId is required'], Response::HTTP_BAD_REQUEST);
        }

        $object = $this->loadObject((int) $objectId);
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

    /** @return \Generator<int, AbstractObject> */
    protected function objectsForClass(string $className): \Generator
    {
        $offset = 0;
        $batchSize = 500;
        do {
            $listing = new DataObject\Listing();
            $listing->setObjectTypes([AbstractObject::OBJECT_TYPE_OBJECT, AbstractObject::OBJECT_TYPE_VARIANT]);
            $listing->setCondition('className = ?', [$className]);
            $listing->setOrderKey('id');
            $listing->setOrder('asc');
            $listing->setOffset($offset);
            $listing->setLimit($batchSize);
            $objects = $listing->load();
            foreach ($objects as $object) {
                yield $object;
            }
            $offset += $batchSize;
        } while (count($objects) === $batchSize);
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

    /** @return array<string, int|string|null> */
    private function operationPreview(MoveOperation $operation): array
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

    private function queueOrganization(int $objectId, ActorContext $actor, string $expectedFingerprint): JsonResponse
    {
        $runId = $this->organizeDispatcher->createRun(
            [$objectId],
            TriggerType::Api,
            $actor,
            [$objectId => $expectedFingerprint],
        );
        try {
            $this->organizeDispatcher->dispatchObject(
                $objectId,
                TriggerType::Api,
                $actor,
                $runId,
                $expectedFingerprint,
            );
        } catch (\Throwable $exception) {
            $this->runs->fail($runId, 'Organization could not be queued.');
            $this->logger->error('Asset Pilot API: organization dispatch failed', [
                'run_id' => $runId,
                'object_id' => $objectId,
                'exception' => $exception,
            ]);

            return new JsonResponse(['error' => 'Organization could not be queued.', 'runId' => $runId], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'message' => 'Organization queued',
            'runId' => $runId,
            'statusUrl' => $this->runStatusUrl($runId),
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * @param list<int>          $objectIds
     * @param array<int, string> $expectedFingerprints
     */
    private function queueBulkOrganization(array $objectIds, ActorContext $actor, array $expectedFingerprints, int $batchSize): JsonResponse
    {
        $batches = array_chunk($objectIds, $batchSize);
        $runId = $this->organizeDispatcher->createRun($objectIds, TriggerType::Api, $actor, $expectedFingerprints);
        foreach ($batches as $batchIndex => $batch) {
            try {
                $this->organizeDispatcher->dispatchBulk(
                    $batch,
                    TriggerType::Api,
                    $actor,
                    $runId,
                    array_intersect_key($expectedFingerprints, array_flip($batch)),
                );
            } catch (\Throwable $exception) {
                $this->failUndispatchedBatches($runId, array_slice($batches, $batchIndex));
                $this->logger->error('Asset Pilot API: bulk organization dispatch failed', [
                    'run_id' => $runId,
                    'batch_index' => $batchIndex,
                    'exception' => $exception,
                ]);

                return new JsonResponse(['error' => 'Bulk organization could not be queued.', 'runId' => $runId], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        return new JsonResponse([
            'message' => 'Bulk organization queued',
            'runId' => $runId,
            'statusUrl' => $this->runStatusUrl($runId),
            'objectCount' => count($objectIds),
            'batchCount' => count($batches),
        ], Response::HTTP_ACCEPTED);
    }

    /** @param list<list<int>> $batches */
    private function failUndispatchedBatches(string $runId, array $batches): void
    {
        foreach ($batches as $batch) {
            foreach ($batch as $objectId) {
                $this->runs->completeItem(
                    $runId,
                    'object:' . $objectId,
                    OperationRunItemStatus::Failed,
                    error: 'Bulk organization could not be queued.',
                );
            }
        }
        $this->runs->finish($runId);
    }

    /** @param list<\Oronts\AssetPilotBundle\Model\OperationResult> $results */
    private function operationItemStatus(array $results): OperationRunItemStatus
    {
        if ($results === [] || array_all($results, static fn ($result): bool => $result->status === OperationStatus::Skipped)) {
            return OperationRunItemStatus::Skipped;
        }
        if (array_any($results, static fn ($result): bool => $result->status === OperationStatus::Failed)) {
            return OperationRunItemStatus::Failed;
        }

        return OperationRunItemStatus::Completed;
    }

    private function bulkItemStatus(BulkObjectStatus $status): OperationRunItemStatus
    {
        return match ($status) {
            BulkObjectStatus::Succeeded => OperationRunItemStatus::Completed,
            BulkObjectStatus::Skipped => OperationRunItemStatus::Skipped,
            BulkObjectStatus::Failed => OperationRunItemStatus::Failed,
        };
    }

    private function runItemKey(int $objectId): string
    {
        return 'object:' . $objectId;
    }

    private function runStatusUrl(string $runId): string
    {
        return $this->urlGenerator->generate('oronts_asset_pilot_operation_run_get', ['id' => $runId]);
    }
}
