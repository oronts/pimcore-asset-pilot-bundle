<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Api\Serialization\ApiDateFormatterInterface;
use Oronts\AssetPilotBundle\Controller\Api\Support\DecodesReviewedPlanRequest;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Exception\DeliveryRetryPlanException;
use Oronts\AssetPilotBundle\Model\DeadOperationDelivery;
use Oronts\AssetPilotBundle\Model\ReviewedDeliveryRetry;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Service\OperationDeliveryRetryCoordinatorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class OperationDeliveryRetryController
{
    use DecodesReviewedPlanRequest;

    public function __construct(
        private readonly OperationDeliveryRetryCoordinatorInterface $retries,
        private readonly ElementAuthorizationInterface $authorization,
        private readonly LoggerInterface $logger,
        private readonly ApiDateFormatterInterface $dates,
    ) {}

    #[Route('/operations/deliveries/retry', name: 'oronts_asset_pilot_operation_delivery_retry', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Admin->value)]
    public function retry(Request $request): JsonResponse
    {
        $input = $this->decodeReviewedPlanRequest($request);
        if ($input instanceof JsonResponse) {
            return $input;
        }

        try {
            $actor = $this->authorization->currentActor();
            $review = $input['apply']
                ? $this->retries->apply($input['limit'], $actor, $input['planToken'])
                : $this->retries->preview($input['limit'], $actor);

            return new JsonResponse($this->serialize($review));
        } catch (DeliveryRetryPlanException $e) {
            return new JsonResponse(
                ['error' => $e->getMessage()],
                $e->status === ApplyPlanStatus::Malformed
                    ? JsonResponse::HTTP_BAD_REQUEST
                    : JsonResponse::HTTP_CONFLICT,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: dead operation delivery retry failed.', ['exception' => $e]);

            return new JsonResponse(['error' => 'Dead operation delivery retry failed.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** @return array<string, mixed> */
    private function serialize(ReviewedDeliveryRetry $review): array
    {
        return [
            'applied' => $review->applied,
            'planToken' => $review->planToken,
            'count' => count($review->deliveries),
            'deliveries' => array_map(fn (DeadOperationDelivery $delivery): array => [
                'deliveryId' => $delivery->deliveryId,
                'operationId' => $delivery->operationId,
                'deliveryKey' => $delivery->deliveryKey,
                'observerId' => $delivery->observerId,
                'outcome' => $delivery->outcome->value,
                'attempts' => $delivery->attempts,
                'lastError' => $delivery->lastError,
                'updatedAt' => $this->dates->fromDatabase($delivery->updatedAt),
                'fingerprint' => $delivery->fingerprint,
            ], $review->deliveries),
        ];
    }
}
