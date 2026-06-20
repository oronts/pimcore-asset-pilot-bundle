<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Audit\AuditLoggerInterface;
use Oronts\AssetPilotBundle\Controller\Api\Support\StreamsCsv;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Enum\RevertFailure;
use Oronts\AssetPilotBundle\Exception\RevertException;
use Oronts\AssetPilotBundle\Service\OperationReverter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AuditController
{
    use StreamsCsv;

    public function __construct(
        protected readonly AuditLoggerInterface $auditLogger,
        protected readonly LoggerInterface $logger,
        protected readonly OperationReverter $operationReverter,
    ) {}

    #[Route('/audit', name: 'oronts_asset_pilot_audit', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));

        $filters = array_filter([
            'object_class' => $request->query->get('class'),
            'status' => $request->query->get('status'),
            'rule_name' => $request->query->get('ruleName'),
        ]);

        $this->logger->debug('Asset Pilot API: audit list requested', [
            'page' => $page,
            'limit' => $limit,
            'filters' => $filters,
        ]);

        $result = $this->auditLogger->getPaginated(
            $page,
            $limit,
            $filters,
            $request->query->get('sort'),
            $request->query->get('order'),
        );

        return new JsonResponse($result);
    }

    #[Route('/audit/export', name: 'oronts_asset_pilot_audit_export', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function export(Request $request): StreamedResponse
    {
        $filters = array_filter([
            'object_class' => $request->query->get('class'),
            'status' => $request->query->get('status'),
            'rule_name' => $request->query->get('ruleName'),
        ]);

        $rows = (function () use ($filters): \Generator {
            foreach ($this->auditLogger->iterateForExport($filters) as $item) {
                yield [
                    $item['id'] ?? '',
                    $item['asset_id'] ?? '',
                    $item['asset_path_from'] ?? '',
                    $item['asset_path_to'] ?? '',
                    $item['object_id'] ?? '',
                    $item['object_class'] ?? '',
                    $item['rule_name'] ?? '',
                    $item['trigger_type'] ?? '',
                    $item['status'] ?? '',
                    $item['duration_ms'] ?? '',
                    $item['error_message'] ?? '',
                    $item['created_at'] ?? '',
                ];
            }
        })();

        return $this->streamCsv(
            'asset-pilot-audit-' . date('Y-m-d') . '.csv',
            ['ID', 'Asset ID', 'From', 'To', 'Object ID', 'Class', 'Rule', 'Trigger', 'Status', 'Duration (ms)', 'Error', 'Date'],
            $rows,
        );
    }

    #[Route('/audit/{id}/revert', name: 'oronts_asset_pilot_audit_revert', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Admin->value)]
    public function revert(int $id): JsonResponse
    {
        try {
            $result = $this->operationReverter->revertById($id);
        } catch (RevertException $e) {
            return new JsonResponse(
                ['error' => $e->getMessage(), ...$e->context],
                $this->revertStatus($e->reason),
            );
        }

        return new JsonResponse(['message' => 'Operation reverted successfully', 'newPath' => $result->toPath]);
    }

    private function revertStatus(RevertFailure $reason): int
    {
        return match ($reason) {
            RevertFailure::AuditEntryNotFound, RevertFailure::AssetNotFound => Response::HTTP_NOT_FOUND,
            RevertFailure::NotCompleted => Response::HTTP_BAD_REQUEST,
            RevertFailure::PermissionDenied => Response::HTTP_FORBIDDEN,
            RevertFailure::PathConflict => Response::HTTP_CONFLICT,
            RevertFailure::ExecutionFailed => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

}
