<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Audit\AuditLogger;
use Oronts\AssetPilotBundle\Engine\RuleEngine;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Message\BulkOrganizeMessage;
use Oronts\AssetPilotBundle\Message\OrganizeAssetsMessage;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractor;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class OperationsController
{
    public function __construct(
        protected readonly AssetOrganizer $organizer,
        protected readonly MessageBusInterface $messageBus,
        protected readonly AuditLogger $auditLogger,
        protected readonly RuleEngine $ruleEngine,
        protected readonly AssetFieldExtractor $fieldExtractor,
        protected readonly LoggerInterface $logger,
        protected readonly int $defaultBatchSize = 50,
    ) {}

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
        $dryRun = $data['dryRun'] ?? false;
        $async = $data['async'] ?? false;

        $this->logger->info('Asset Pilot API: organize triggered for object {id}', [
            'id' => $objectId,
            'dryRun' => $dryRun,
            'async' => $async,
        ]);

        if ($dryRun) {
            $operations = $this->organizer->dryRun($object, TriggerType::Api);
            return new JsonResponse([
                'dryRun' => true,
                'operations' => array_map(static fn ($op) => [
                    'assetId' => $op->assetId,
                    'sourcePath' => $op->sourcePath,
                    'targetPath' => $op->targetPath,
                    'ruleName' => $op->ruleName,
                ], $operations),
            ]);
        }

        if ($async) {
            $this->messageBus->dispatch(Envelope::wrap(
                new OrganizeAssetsMessage(
                    objectId: (int) $objectId,
                    triggerType: TriggerType::Api,
                    dispatchedAt: time(),
                ),
                [new DeduplicateStamp('asset_pilot_organize_' . $objectId, 30.0)],
            ));
            return new JsonResponse(['message' => 'Organization queued'], Response::HTTP_ACCEPTED);
        }

        $results = $this->organizer->organize($object, TriggerType::Api);

        return new JsonResponse([
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

    #[Route('/organize/preview', name: 'oronts_asset_pilot_organize_preview', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function preview(Request $request): JsonResponse
    {
        $resolved = $this->resolveObjectFromBody($request);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$object] = $resolved;

        $operations = $this->organizer->dryRun($object, TriggerType::Api);

        return new JsonResponse([
            'objectId' => $object->getId(),
            'operations' => array_map(static fn ($op) => [
                'assetId' => $op->assetId,
                'sourcePath' => $op->sourcePath,
                'targetPath' => $op->targetPath,
                'ruleName' => $op->ruleName,
                'objectClass' => $op->objectClass,
            ], $operations),
        ]);
    }

    #[Route('/organize/bulk', name: 'oronts_asset_pilot_organize_bulk', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function organizeBulk(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $className = $data['className'] ?? null;
        $objectIds = $data['objectIds'] ?? [];
        $async = $data['async'] ?? true;

        if ($className === null && empty($objectIds)) {
            return new JsonResponse(['error' => 'className or objectIds required'], Response::HTTP_BAD_REQUEST);
        }

        // Resolve object IDs from class name if not provided directly
        if (empty($objectIds) && $className !== null) {
            $listing = new DataObject\Listing();
            $listing->setObjectTypes([AbstractObject::OBJECT_TYPE_OBJECT, AbstractObject::OBJECT_TYPE_VARIANT]);
            $listing->setCondition('className = ?', [$className]);
            foreach ($listing as $obj) {
                $objectIds[] = $obj->getId();
            }
        }

        if (empty($objectIds)) {
            return new JsonResponse(['error' => 'No objects found'], Response::HTTP_NOT_FOUND);
        }

        $this->logger->info('Asset Pilot API: bulk organize triggered for {count} objects', [
            'count' => count($objectIds),
        ]);

        if ($data['dryRun'] ?? false) {
            $operations = [];
            foreach ($objectIds as $oid) {
                $object = AbstractObject::getById((int) $oid);
                if ($object === null) {
                    continue;
                }
                foreach ($this->organizer->dryRun($object, TriggerType::Api) as $op) {
                    $operations[] = [
                        'assetId' => $op->assetId,
                        'sourcePath' => $op->sourcePath,
                        'targetPath' => $op->targetPath,
                        'ruleName' => $op->ruleName,
                        'objectClass' => $op->objectClass,
                        'status' => $op->status->value,
                    ];
                }
            }

            return new JsonResponse([
                'dryRun' => true,
                'objectCount' => count($objectIds),
                'operations' => $operations,
            ]);
        }

        if ($async) {
            $batchSize = max(1, (int) ($data['batchSize'] ?? $this->defaultBatchSize));
            $batches = array_chunk($objectIds, $batchSize);
            foreach ($batches as $batch) {
                $key = 'asset_pilot_bulk_' . md5(implode(',', $batch));
                $this->messageBus->dispatch(Envelope::wrap(
                    new BulkOrganizeMessage(
                        objectIds: $batch,
                        triggerType: TriggerType::Api,
                        dispatchedAt: time(),
                    ),
                    [new DeduplicateStamp($key, 60.0)],
                ));
            }
            return new JsonResponse([
                'message' => 'Bulk organization queued',
                'objectCount' => count($objectIds),
                'batchCount' => count($batches),
            ], Response::HTTP_ACCEPTED);
        }

        $results = $this->organizer->organizeBulk($objectIds, TriggerType::Api);

        return new JsonResponse([
            'objectCount' => count($objectIds),
            'resultCount' => count($results),
        ]);
    }

    #[Route('/operations/bulk-preview', name: 'oronts_asset_pilot_bulk_preview', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function bulkPreview(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $className = $data['className'] ?? null;
        if ($className === null) {
            return new JsonResponse(['error' => 'className is required'], Response::HTTP_BAD_REQUEST);
        }

        $page = max(1, (int) ($data['page'] ?? 1));
        $limit = min(200, max(1, (int) ($data['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $listing = new DataObject\Listing();
        $listing->setObjectTypes([AbstractObject::OBJECT_TYPE_OBJECT, AbstractObject::OBJECT_TYPE_VARIANT]);
        $listing->setCondition('className = ?', [$className]);

        $total = $listing->getTotalCount();

        $listing->setLimit($limit);
        $listing->setOffset($offset);
        $listing->setOrderKey('id');
        $listing->setOrder('asc');

        $objects = [];
        foreach ($listing as $obj) {
            $objects[] = [
                'id' => $obj->getId(),
                'key' => $obj->getKey(),
                'className' => $obj->getClassName(),
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

    /**
     * Decode the JSON body and resolve the required `objectId` to a loaded object.
     *
     * @return array{0: AbstractObject, 1: array<string, mixed>}|JsonResponse the loaded object plus
     *                                                                         the decoded body, or the error response to return
     */
    protected function resolveObjectFromBody(Request $request): array|JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $objectId = $data['objectId'] ?? null;
        if ($objectId === null) {
            return new JsonResponse(['error' => 'objectId is required'], Response::HTTP_BAD_REQUEST);
        }

        $object = $this->loadObject((int) $objectId);
        if ($object === null) {
            return new JsonResponse(['error' => 'Object not found'], Response::HTTP_NOT_FOUND);
        }

        return [$object, $data];
    }

    protected function loadObject(int $id): ?AbstractObject
    {
        return AbstractObject::getById($id);
    }
}
