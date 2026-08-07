<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Audit\AuditQueryInterface;
use Oronts\AssetPilotBundle\Controller\Api\Support\DecodesJsonObject;
use Oronts\AssetPilotBundle\Controller\Api\Support\ReadsRequestScalars;
use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Enum\RulePreviewPlanStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\DriftItem;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Model\RuleOverlap;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Service\AssetOrganizerInterface;
use Oronts\AssetPilotBundle\Service\LocationDriftServiceInterface;
use Oronts\AssetPilotBundle\Service\OrganizePlanFingerprint;
use Oronts\AssetPilotBundle\Service\Query\Pagination;
use Oronts\AssetPilotBundle\Service\RuleOverlapAnalyzerInterface;
use Oronts\AssetPilotBundle\Service\RulePortabilityInterface;
use Oronts\AssetPilotBundle\Service\RulePreviewPlanServiceInterface;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class RulesController
{
    use DecodesJsonObject;
    use ReadsRequestScalars;

    public function __construct(
        protected readonly RuleEngineInterface $ruleEngine,
        protected readonly AssetOrganizerInterface $assetOrganizer,
        protected readonly AuditQueryInterface $auditLogger,
        protected readonly RulePortabilityInterface $portability,
        protected readonly RuleOverlapAnalyzerInterface $overlapAnalyzer,
        protected readonly LocationDriftServiceInterface $driftService,
        protected readonly RulePreviewPlanServiceInterface $previewPlans,
        private readonly OrganizePlanFingerprint $organizeFingerprints,
        protected readonly ElementAuthorizationInterface $authorization,
        protected readonly LoggerInterface $logger,
    ) {}

    #[Route('/rules/drift', name: 'oronts_asset_pilot_rules_drift', methods: ['GET'], priority: 1)]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function drift(Request $request): JsonResponse
    {
        $className = trim((string) $request->query->get('class', ''));
        if ($className === '') {
            return new JsonResponse(['error' => 'Query parameter "class" is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        [$page, $limit] = Pagination::fromRequest($request, 200);

        try {
            $result = $this->driftService->driftForClass($className, $page, $limit);

            return new JsonResponse([
                'items' => array_map(static fn (DriftItem $item): array => [
                    'assetId' => $item->assetId,
                    'currentPath' => $item->currentPath,
                    'expectedPath' => $item->expectedPath,
                    'ruleName' => $item->ruleName,
                    'eligibility' => $item->eligibility->value,
                    'reason' => $item->reason,
                ], $result['items']),
                'objectsScanned' => $result['objectsScanned'],
                'page' => $result['page'],
                'limit' => $result['limit'],
                'truncated' => $result['truncated'] ?? false,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to compute location drift.', ['exception' => $e]);

            return new JsonResponse(
                ['error' => 'Failed to compute location drift.'],
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

    #[Route('/rules/overlap', name: 'oronts_asset_pilot_rules_overlap', methods: ['GET'], priority: 1)]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function overlap(): JsonResponse
    {
        try {
            $overlaps = array_map(static fn (RuleOverlap $overlap): array => [
                'ruleA' => $overlap->ruleA,
                'ruleB' => $overlap->ruleB,
                'class' => $overlap->class,
                'sharedFields' => $overlap->sharedFields,
                'higherPriority' => $overlap->higherPriority,
                'samePriority' => $overlap->samePriority,
            ], $this->overlapAnalyzer->analyze());

            return new JsonResponse(['overlaps' => $overlaps]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to analyze rule overlap.', ['exception' => $e]);

            return new JsonResponse(
                ['error' => 'Failed to analyze rule overlap.'],
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

    #[Route('/rules/export', name: 'oronts_asset_pilot_rules_export', methods: ['GET'], priority: 1)]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function export(): JsonResponse
    {
        try {
            $export = $this->portability->export();
            $export['rules'] = (object) ($export['rules'] ?? []);

            return new JsonResponse($export);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to export rules.', ['exception' => $e]);

            return new JsonResponse(
                ['error' => 'Failed to export rules.'],
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

    #[Route('/rules/diff', name: 'oronts_asset_pilot_rules_diff', methods: ['POST'], priority: 1)]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function diff(Request $request): JsonResponse
    {
        $artifact = $this->decodeJsonObject($request);
        if ($artifact instanceof JsonResponse) {
            return $artifact;
        }

        try {
            $diff = $this->portability->diff($artifact);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to diff rules.', ['exception' => $e]);

            return new JsonResponse(
                ['error' => 'Failed to diff rules.'],
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return new JsonResponse([
            'added' => (object) $diff->added,
            'removed' => (object) $diff->removed,
            'changed' => (object) $diff->changed,
            'unchanged' => $diff->unchanged,
            'hasChanges' => $diff->hasChanges(),
        ]);
    }

    #[Route('/rules', name: 'oronts_asset_pilot_rules', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function list(): JsonResponse
    {
        try {
            $rules = $this->ruleEngine->getRules();

            $response = array_map(static fn (Rule $rule): array => [
                'name' => $rule->name,
                'class' => $rule->class,
                'fields' => $rule->fields,
                'condition' => $rule->condition,
                'targetPath' => $rule->targetPath,
                'strategy' => $rule->strategy->value,
                'priority' => $rule->priority,
                'enabled' => $rule->enabled,
                'filters' => (object) $rule->filters,
            ], $rules);

            $this->logger->debug('Listed {count} rules.', ['count' => count($response)]);

            return new JsonResponse($response);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list rules.', ['exception' => $e]);

            return new JsonResponse(
                ['error' => 'Failed to list rules.'],
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

    #[Route('/rules/{name}', name: 'oronts_asset_pilot_rule_detail', methods: ['GET'], priority: -1)]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function detail(string $name): JsonResponse
    {
        try {
            $rules = $this->ruleEngine->getRules();
            $rule = null;
            foreach ($rules as $r) {
                if ($r->name === $name) {
                    $rule = $r;
                    break;
                }
            }

            if ($rule === null) {
                return new JsonResponse(
                    ['error' => sprintf('Rule "%s" not found.', $name)],
                    JsonResponse::HTTP_NOT_FOUND,
                );
            }

            $stats = $this->auditLogger->getStatsByRule($name);

            return new JsonResponse([
                'name' => $rule->name,
                'class' => $rule->class,
                'fields' => $rule->fields,
                'condition' => $rule->condition,
                'targetPath' => $rule->targetPath,
                'strategy' => $rule->strategy->value,
                'priority' => $rule->priority,
                'enabled' => $rule->enabled,
                'filters' => (object) $rule->filters,
                'stats' => (object) $stats,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get rule detail for "{rule}".', [
                'rule' => $name,
                'exception' => $e,
            ]);

            return new JsonResponse(
                ['error' => 'Failed to get rule detail.'],
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

    #[Route('/rules/{name}/preview', name: 'oronts_asset_pilot_rules_preview', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function preview(string $name, Request $request): JsonResponse
    {
        try {
            $rule = $this->findRule($name);
            if ($rule === null) {
                return new JsonResponse(['error' => sprintf('Rule "%s" not found.', $name)], JsonResponse::HTTP_NOT_FOUND);
            }

            $objectId = $request->query->getInt('objectId');
            if ($objectId <= 0) {
                return new JsonResponse(
                    ['error' => 'Query parameter "objectId" is required and must be a positive integer.'],
                    JsonResponse::HTTP_BAD_REQUEST,
                );
            }

            $object = $this->loadObject($objectId);
            if ($object === null) {
                return new JsonResponse(
                    ['error' => sprintf('Object with ID %d not found.', $objectId)],
                    JsonResponse::HTTP_NOT_FOUND,
                );
            }
            if (!$this->authorization->isAllowed($object, 'view')) {
                return new JsonResponse(['error' => 'Object access is not permitted.'], JsonResponse::HTTP_FORBIDDEN);
            }

            $operations = $this->assetOrganizer->dryRun($object, TriggerType::Api, $name);

            $filtered = array_map(static fn ($op) => [
                'assetId' => $op->assetId,
                'sourcePath' => $op->sourcePath,
                'targetPath' => $op->targetPath,
                'ruleName' => $op->ruleName,
            ], $operations);

            $this->logger->info('Preview for rule "{rule}" on object {objectId}: {count} operations.', [
                'rule' => $name,
                'objectId' => $objectId,
                'count' => count($filtered),
            ]);

            return new JsonResponse([
                'operations' => $filtered,
                'planToken' => $this->previewPlans->issue($rule, $object, $this->authorization->currentActor(), $operations),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to preview rule "{rule}".', [
                'rule' => $name,
                'exception' => $e,
            ]);

            return new JsonResponse(
                ['error' => 'Failed to generate preview.'],
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

    #[Route('/rules/{name}/apply', name: 'oronts_asset_pilot_rules_apply', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function apply(string $name, Request $request): JsonResponse
    {
        $rule = $this->findRule($name);
        if ($rule === null) {
            return new JsonResponse(['error' => sprintf('Rule "%s" not found.', $name)], JsonResponse::HTTP_NOT_FOUND);
        }

        $data = $this->decodeJsonObject($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $objectId = $this->requestPositiveInt($data, 'objectId', null);
        if ($objectId instanceof JsonResponse) {
            return $objectId;
        }

        $planToken = $data['planToken'] ?? null;
        if (!is_string($planToken) || $planToken === '') {
            return new JsonResponse(
                ['error' => 'planToken is required and must be a valid preview token.'],
                JsonResponse::HTTP_BAD_REQUEST,
            );
        }

        $object = $this->loadObject($objectId);
        if ($object === null) {
            return new JsonResponse(['error' => sprintf('Object with ID %d not found.', $objectId)], JsonResponse::HTTP_NOT_FOUND);
        }
        if (!$this->authorization->isAllowed($object, 'publish')) {
            return new JsonResponse(['error' => 'Object mutation is not permitted.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $actor = $this->authorization->currentActor();
        $currentOperations = $this->assetOrganizer->dryRun($object, TriggerType::Api, $name);
        $reviewedFingerprint = $this->organizeFingerprints->forOperations($object, $currentOperations);
        $planStatus = $this->previewPlans->claim($planToken, $rule, $object, $actor, $currentOperations);
        if ($planStatus === RulePreviewPlanStatus::Malformed) {
            return new JsonResponse(
                ['error' => 'planToken is required and must be a valid preview token.'],
                JsonResponse::HTTP_BAD_REQUEST,
            );
        }
        if ($planStatus !== RulePreviewPlanStatus::Valid) {
            return new JsonResponse(
                ['error' => 'The preview plan is stale or does not match this request. Run the preview again.'],
                JsonResponse::HTTP_CONFLICT,
            );
        }

        $results = $this->assetOrganizer->organize(
            $object,
            TriggerType::Api,
            $name,
            $reviewedFingerprint,
        );

        return new JsonResponse([
            'rule' => $name,
            'results' => array_map(static fn ($r): array => [
                'status' => $r->status->value,
                'message' => $r->message,
                'operation' => $r->operation !== null ? [
                    'assetId' => $r->operation->assetId,
                    'sourcePath' => $r->operation->sourcePath,
                    'targetPath' => $r->operation->targetPath,
                    'ruleName' => $r->operation->ruleName,
                ] : null,
            ], $results),
        ]);
    }

    private function findRule(string $name): ?Rule
    {
        foreach ($this->ruleEngine->getRules() as $rule) {
            if ($rule->name === $name) {
                return $rule;
            }
        }

        return null;
    }

    protected function loadObject(int $objectId): ?AbstractObject
    {
        return AbstractObject::getById($objectId);
    }
}
