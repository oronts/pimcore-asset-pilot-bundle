<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Health\HealthCheckerInterface;
use Oronts\AssetPilotBundle\Model\HealthCheckResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class HealthController
{
    public function __construct(
        protected readonly HealthCheckerInterface $healthChecker,
        protected readonly LoggerInterface $logger,
    ) {}

    #[Route('/health', name: 'oronts_asset_pilot_health', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function health(): JsonResponse
    {
        try {
            $results = $this->healthChecker->run();

            return new JsonResponse([
                'status' => $this->healthChecker->overall($results)->value,
                'checks' => array_map(static fn (HealthCheckResult $result): array => [
                    'name' => $result->name,
                    'status' => $result->status->value,
                    'message' => $result->message,
                    'details' => $result->details,
                ], $results),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Health check failed.', ['exception' => $e]);

            return new JsonResponse(
                ['error' => 'Health check failed.'],
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

    #[Route('/health/readiness', name: 'oronts_asset_pilot_health_readiness', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function readiness(): JsonResponse
    {
        try {
            $status = $this->healthChecker->overall($this->healthChecker->run());

            return new JsonResponse(
                ['status' => $status->value],
                $status === HealthStatus::Critical ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Readiness check failed.', ['exception' => $e]);

            return new JsonResponse(['status' => HealthStatus::Critical->value], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }
}
