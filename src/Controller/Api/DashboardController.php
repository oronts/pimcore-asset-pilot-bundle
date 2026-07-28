<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Api\Serialization\ApiDateFormatterInterface;
use Oronts\AssetPilotBundle\Audit\AuditQueryInterface;
use Oronts\AssetPilotBundle\Controller\Api\Support\AuditRowDates;
use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DashboardController
{
    public function __construct(
        protected readonly AuditQueryInterface $auditLogger,
        protected readonly RuleEngineInterface $ruleEngine,
        protected readonly LoggerInterface $logger,
        private readonly ApiDateFormatterInterface $dates,
    ) {}

    #[Route('/dashboard', name: 'oronts_asset_pilot_dashboard', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function dashboard(): JsonResponse
    {
        try {
            $stats = $this->auditLogger->getStats();
            $recent = array_map(
                fn (array $row): array => AuditRowDates::normalize($row, $this->dates),
                $this->auditLogger->getRecent(10),
            );

            $this->logger->debug('Asset Pilot dashboard requested.');

            return new JsonResponse([
                'totalOrganized' => ($stats[OperationStatus::Completed->value] ?? 0)
                    + ($stats[OperationStatus::CompletedWithObserverError->value] ?? 0),
                'totalOrganizedWithWarnings' => $stats[OperationStatus::CompletedWithObserverError->value] ?? 0,
                'totalPending' => $stats['pending'] ?? 0,
                'totalFailed' => $stats['failed'] ?? 0,
                'totalSkipped' => $stats['skipped'] ?? 0,
                'rulesCount' => count($this->ruleEngine->getRules()),
                'recentOperations' => $recent,
                'operationsByClass' => $stats['by_class'] ?? [],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to load dashboard.', ['exception' => $e]);

            return new JsonResponse(
                ['error' => 'Failed to load dashboard data.'],
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

    #[Route('/dashboard/class-stats', name: 'oronts_asset_pilot_dashboard_class_stats', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function classStats(): JsonResponse
    {
        try {
            $breakdown = $this->auditLogger->getClassBreakdown();

            return new JsonResponse($breakdown);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to load class stats.', ['exception' => $e]);

            return new JsonResponse(
                ['error' => 'Failed to load class stats.'],
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

}
