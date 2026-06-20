<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Audit\AuditLoggerInterface;
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

    #[Route('/audit/stats', name: 'oronts_asset_pilot_audit_stats', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function stats(): JsonResponse
    {
        return new JsonResponse($this->auditLogger->getStats());
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

        return new StreamedResponse(function () use ($filters) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Asset ID', 'From', 'To', 'Object ID', 'Class', 'Rule', 'Trigger', 'Status', 'Duration (ms)', 'Error', 'Date']);

            $written = 0;
            foreach ($this->auditLogger->iterateForExport($filters) as $item) {
                fputcsv($handle, array_map($this->sanitizeCsvCell(...), [
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
                ]));

                if ((++$written % 1000) === 0) {
                    flush();
                }
            }

            fclose($handle);
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="asset-pilot-audit-' . date('Y-m-d') . '.csv"',
        ]);
    }

    #[Route('/audit/by-rule/{ruleName}/assets', name: 'oronts_asset_pilot_audit_rule_assets', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function assetsByRule(string $ruleName, Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(200, max(1, (int) $request->query->get('limit', 50)));

        $filters = array_filter([
            'since' => $request->query->get('since'),
            'object_class' => $request->query->get('class'),
        ]);

        $result = $this->auditLogger->getDistinctAssetsByRule($ruleName, $page, $limit, $filters);

        return new JsonResponse($result);
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

    // A cell starting with = + - @ (or a control char) is executed as a formula by Excel/Sheets;
    // prefix it with a quote to neutralize CSV formula injection.
    protected function sanitizeCsvCell(mixed $value): string
    {
        $value = (string) $value;

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r", "\n"], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
