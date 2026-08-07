<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api\Support;

use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Shared mapping from an apply-plan claim outcome to its HTTP rejection, used by every controller that claims a
 * reviewed plan before applying it, so the status codes and messages stay identical across endpoints.
 */
trait RejectsClaimedPlan
{
    /** The HTTP rejection for a claim outcome, or null when the plan was freshly claimed and the caller may proceed. */
    private function rejectClaimedPlan(ApplyPlanStatus $status): ?JsonResponse
    {
        return match ($status) {
            ApplyPlanStatus::Claimed => null,
            ApplyPlanStatus::Malformed => new JsonResponse(['error' => 'The apply plan token is malformed.'], JsonResponse::HTTP_BAD_REQUEST),
            default => new JsonResponse(['error' => 'The apply plan is stale or was already used. Preview again.'], JsonResponse::HTTP_CONFLICT),
        };
    }

    /** HTTP status for an apply-plan failure surfaced as an exception: a malformed token is a bad request, anything else a conflict. */
    private function planStatusHttpStatus(ApplyPlanStatus $status): int
    {
        return $status === ApplyPlanStatus::Malformed ? JsonResponse::HTTP_BAD_REQUEST : JsonResponse::HTTP_CONFLICT;
    }
}
