<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Controller\Api\Support\DecodesReviewedPlanRequest;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Exception\OperationRecoveryPlanException;
use Oronts\AssetPilotBundle\Model\OperationRecoveryResult;
use Oronts\AssetPilotBundle\Model\ReviewedOperationRecovery;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\OperationRecoveryCoordinatorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class OperationRecoveryController
{
    use DecodesReviewedPlanRequest;

    public function __construct(
        private readonly OperationRecoveryCoordinatorInterface $recovery,
        private readonly ElementAuthorization $authorization,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/operations/recovery', name: 'oronts_asset_pilot_operation_recovery', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Admin->value)]
    public function recover(Request $request): JsonResponse
    {
        $input = $this->decodeReviewedPlanRequest($request);
        if ($input instanceof JsonResponse) {
            return $input;
        }

        try {
            $result = $input['apply']
                ? $this->recovery->apply($input['limit'], $this->authorization->currentActor(), $input['planToken'])
                : $this->recovery->preview($input['limit'], $this->authorization->currentActor());

            return new JsonResponse($this->serialize($result));
        } catch (OperationRecoveryPlanException $e) {
            return new JsonResponse(
                ['error' => $e->getMessage()],
                $e->status === ApplyPlanStatus::Malformed
                    ? JsonResponse::HTTP_BAD_REQUEST
                    : JsonResponse::HTTP_CONFLICT,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: operation recovery failed.', ['exception' => $e]);

            return new JsonResponse(['error' => 'Operation recovery failed.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** @return array<string, mixed> */
    private function serialize(ReviewedOperationRecovery $review): array
    {
        return [
            'applied' => $review->applied,
            'planToken' => $review->planToken,
            'unresolved' => $review->unresolvedCount(),
            'results' => array_map(static fn (OperationRecoveryResult $result): array => [
                'operationId' => $result->operationId,
                'assetId' => $result->assetId,
                'kind' => $result->kind->value,
                'classification' => $result->status->value,
                'journalUpdated' => $result->journalUpdated,
                'message' => $result->message,
            ], $review->results),
        ];
    }
}
