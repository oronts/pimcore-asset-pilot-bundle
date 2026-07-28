<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use Oronts\AssetPilotBundle\Api\Serialization\ApiDateFormatterInterface;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Enum\OperationRunKind;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Service\OperationRunActor;
use Oronts\AssetPilotBundle\Service\OperationRunExecutorInterface;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class OperationRunsController
{
    public function __construct(
        private readonly OperationRunStoreInterface $runs,
        private readonly OperationRunExecutorInterface $executor,
        private readonly ElementAuthorizationInterface $authorization,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ApiDateFormatterInterface $dates,
    ) {}

    #[Route('/operations/runs', name: 'oronts_asset_pilot_operation_run_list', methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function list(Request $request): JsonResponse
    {
        $rawLimit = $request->query->get('limit', '20');
        if (!is_string($rawLimit) || preg_match('/^[1-9][0-9]*$/D', $rawLimit) !== 1 || (int) $rawLimit > 100) {
            return new JsonResponse(['error' => 'The limit must be an integer between 1 and 100.'], Response::HTTP_BAD_REQUEST);
        }

        $runs = array_map(
            fn (array $run): array => $this->serializeSummary($run),
            $this->runs->recent($this->authorization->currentActor(), (int) $rawLimit),
        );

        return new JsonResponse(['items' => $runs, 'limit' => (int) $rawLimit]);
    }

    #[Route('/operations/runs/{id}', name: 'oronts_asset_pilot_operation_run_get', requirements: ['id' => '[a-f0-9]{32}'], methods: ['GET'])]
    #[IsGranted(AssetPilotPermission::View->value)]
    public function get(string $id): JsonResponse
    {
        $run = $this->runs->get($id, $this->authorization->currentActor());
        if ($run === null) {
            return new JsonResponse(['error' => 'Operation run not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->serialize($run));
    }

    #[Route('/operations/runs/{id}/cancel', name: 'oronts_asset_pilot_operation_run_cancel', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function cancel(string $id): JsonResponse
    {
        $actor = $this->authorization->currentActor();
        if ($this->runs->get($id, $actor) === null) {
            return new JsonResponse(['error' => 'Operation run not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->runs->requestCancellation($id, $actor)) {
            return new JsonResponse(['error' => 'Only pending, queued, or running operations can be cancelled.'], Response::HTTP_CONFLICT);
        }

        $status = $this->runs->finish($id);

        return new JsonResponse(
            ['runId' => $id, 'status' => $status->value],
            $status === OperationRunStatus::Cancelled ? Response::HTTP_OK : Response::HTTP_ACCEPTED,
        );
    }

    #[Route('/operations/runs/{id}/retry', name: 'oronts_asset_pilot_operation_run_retry', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Operate->value)]
    public function retry(string $id): JsonResponse
    {
        $actor = $this->authorization->currentActor();
        $run = $this->runs->get($id, $actor);
        if ($run === null) {
            return new JsonResponse(['error' => 'Operation run not found.'], Response::HTTP_NOT_FOUND);
        }

        $kind = OperationRunKind::tryFrom((string) ($run['kind'] ?? ''));
        if ($kind === null || !$this->executor->supports($kind)) {
            return new JsonResponse(['error' => 'This operation kind cannot be retried.'], Response::HTTP_CONFLICT);
        }

        // DuplicateMerge is Admin-tier; re-assert it here since the retry endpoint is only Operate-gated.
        if ($kind === OperationRunKind::DuplicateMerge && !$this->authorization->hasGlobalPermission(AssetPilotPermission::Admin->value, $actor)) {
            return new JsonResponse(['error' => 'Retrying a duplicate merge requires the asset_pilot_admin permission.'], Response::HTTP_FORBIDDEN);
        }

        $retryId = $this->runs->retry($id, $actor);
        if ($retryId === null) {
            return new JsonResponse(['error' => 'This operation has no retryable items.'], Response::HTTP_CONFLICT);
        }
        $retry = $this->runs->get($retryId, $actor);
        if ($retry === null) {
            $this->runs->fail($retryId, 'The retry run could not be loaded.');

            return new JsonResponse(['error' => 'The retry operation could not be started.', 'runId' => $retryId], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            $execution = $this->executor->execute($kind, $retryId, $retry, OperationRunActor::fromRun($retry));
        } catch (\InvalidArgumentException $e) {
            $this->runs->fail($retryId, 'The retry run contains unsupported targets.');

            return new JsonResponse(['error' => $e->getMessage(), 'runId' => $retryId], Response::HTTP_CONFLICT);
        } catch (\Throwable) {
            $this->runs->fail($retryId, 'The retry operation could not be dispatched.');

            return new JsonResponse(['error' => 'The retry operation could not be started.', 'runId' => $retryId], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $payload = [
            'runId' => $retryId,
            'retryOf' => $id,
            'status' => $execution->status->value,
            'statusUrl' => $this->urlGenerator->generate('oronts_asset_pilot_operation_run_get', ['id' => $retryId]),
        ];
        if ($execution->dispositions !== []) {
            $payload['dispositions'] = array_map(static fn ($disposition): array => [
                'copyId' => $disposition->copyId,
                'outcome' => $disposition->outcome->value,
                'reason' => $disposition->reason,
            ], $execution->dispositions);
        }

        return new JsonResponse(
            $payload,
            $execution->asynchronous ? Response::HTTP_ACCEPTED : Response::HTTP_OK,
        );
    }

    /** @param array<string, mixed> $run */
    private function serialize(array $run): array
    {
        return [
            ...$this->serializeSummary($run),
            'items' => array_map(fn (array $item): array => [
                'key' => $item['item_key'],
                'targetType' => $item['target_type'],
                'targetId' => $item['target_id'] === null ? null : (int) $item['target_id'],
                'fingerprint' => $item['fingerprint'],
                'status' => $item['status'],
                'attempts' => (int) $item['attempts'],
                'state' => $item['state_payload'] ?? [],
                'result' => $item['result_payload'],
                'error' => $item['error_message'],
                'createdAt' => $this->dates->fromDatabase((string) $item['created_at']),
                'updatedAt' => $this->dates->fromDatabase((string) $item['updated_at']),
                'completedAt' => $this->dates->fromDatabase((string) $item['completed_at']),
            ], $run['items']),
        ];
    }

    /** @param array<string, mixed> $run */
    private function serializeSummary(array $run): array
    {
        return [
            'id' => $run['id'],
            'kind' => $run['kind'],
            'status' => $run['status'],
            'totalCount' => (int) $run['total_count'],
            'processedCount' => (int) $run['processed_count'],
            'succeededCount' => (int) $run['succeeded_count'],
            'skippedCount' => (int) $run['skipped_count'],
            'blockedCount' => (int) ($run['blocked_count'] ?? 0),
            'failedCount' => (int) $run['failed_count'],
            'attempt' => (int) $run['attempt'],
            'retryOf' => $run['retry_of'],
            'request' => $run['request_payload'],
            'error' => $run['error_message'],
            'createdAt' => $this->dates->fromDatabase((string) $run['created_at']),
            'startedAt' => $this->dates->fromDatabase((string) $run['started_at']),
            'updatedAt' => $this->dates->fromDatabase((string) $run['updated_at']),
            'completedAt' => $this->dates->fromDatabase((string) $run['completed_at']),
        ];
    }
}
