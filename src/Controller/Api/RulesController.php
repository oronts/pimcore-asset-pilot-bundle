<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Audit\AuditLogger;
use Oronts\AssetPilotBundle\Engine\RuleEngine;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class RulesController
{
    public function __construct(
        protected readonly RuleEngine $ruleEngine,
        protected readonly AssetOrganizer $assetOrganizer,
        protected readonly AuditLogger $auditLogger,
        protected readonly LoggerInterface $logger,
    ) {}

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

            $operations = $this->assetOrganizer->dryRun($object);

            $filtered = array_values(array_filter(
                $operations,
                static fn ($op): bool => $op->ruleName === $name,
            ));

            $filtered = array_map(static fn ($op) => [
                'assetId' => $op->assetId,
                'sourcePath' => $op->sourcePath,
                'targetPath' => $op->targetPath,
                'ruleName' => $op->ruleName,
            ], $filtered);

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
}
