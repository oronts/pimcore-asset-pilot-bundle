<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Service\MetricsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class MetricsController
{
    public function __construct(
        protected readonly MetricsService $metrics,
        protected readonly LoggerInterface $logger,
    ) {}

    #[Route('/metrics', name: 'oronts_asset_pilot_metrics', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function metrics(): JsonResponse
    {
        try {
            return new JsonResponse($this->metrics->collect());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to collect metrics.', ['exception' => $e]);

            return new JsonResponse(
                ['error' => 'Failed to collect metrics.'],
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }
}
