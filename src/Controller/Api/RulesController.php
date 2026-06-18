<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Audit\AuditLoggerInterface;
use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\RulePortability;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class RulesController
{
    public function __construct(
        protected readonly RuleEngineInterface $ruleEngine,
        protected readonly AssetOrganizer $assetOrganizer,
        protected readonly AuditLoggerInterface $auditLogger,
        protected readonly RulePortability $portability,
        protected readonly LoggerInterface $logger,
    ) {}

    #[Route('/rules/export', name: 'oronts_asset_pilot_rules_export', methods: ['GET'], priority: 1)]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function export(): JsonResponse
    {
        try {
            return new JsonResponse($this->portability->export());
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
        try {
            $artifact = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!is_array($artifact)) {
            return new JsonResponse(['error' => 'Expected a rule-set artifact object.'], JsonResponse::HTTP_BAD_REQUEST);
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
            'added' => $diff->added,
            'removed' => $diff->removed,
            'changed' => $diff->changed,
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
                'filters' => $rule->filters,
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
                'filters' => $rule->filters,
                'stats' => $stats,
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
            $objectId = $request->query->getInt('objectId');
            if ($objectId <= 0) {
                return new JsonResponse(
                    ['error' => 'Query parameter "objectId" is required and must be a positive integer.'],
                    JsonResponse::HTTP_BAD_REQUEST,
                );
            }

            $object = AbstractObject::getById($objectId);
            if ($object === null) {
                return new JsonResponse(
                    ['error' => sprintf('Object with ID %d not found.', $objectId)],
                    JsonResponse::HTTP_NOT_FOUND,
                );
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

            return new JsonResponse($filtered);
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
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!is_array($data) || (int) ($data['objectId'] ?? 0) <= 0) {
            return new JsonResponse(['error' => 'objectId is required and must be a positive integer.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $object = AbstractObject::getById((int) $data['objectId']);
        if ($object === null) {
            return new JsonResponse(['error' => sprintf('Object with ID %d not found.', (int) $data['objectId'])], JsonResponse::HTTP_NOT_FOUND);
        }

        $known = array_filter($this->ruleEngine->getRules(), static fn (Rule $r): bool => $r->name === $name);
        if ($known === []) {
            return new JsonResponse(['error' => sprintf('Rule "%s" not found.', $name)], JsonResponse::HTTP_NOT_FOUND);
        }

        $results = $this->assetOrganizer->organize($object, TriggerType::Api, $name);

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
}
