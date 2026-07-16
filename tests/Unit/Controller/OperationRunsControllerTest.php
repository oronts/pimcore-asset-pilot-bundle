<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Controller\Api\OperationRunsController;
use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Enum\OperationRunKind;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Merge\CopyDisposition;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\OperationRunExecution;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\OperationRunExecutorInterface;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(OperationRunsController::class)]
final class OperationRunsControllerTest extends TestCase
{
    private const string RUN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string RETRY_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    #[Test]
    public function listsActorScopedRecentRuns(): void
    {
        $actor = ActorContext::user(7);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $summary = $this->runFixture();
        unset($summary['items']);
        $runs->expects(self::once())->method('recent')->with($actor, 25)->willReturn([$summary]);

        $response = $this->controller($runs, $actor)->list(new Request(['limit' => '25']));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(25, $body['limit']);
        self::assertSame(self::RUN_ID, $body['items'][0]['id']);
        self::assertArrayNotHasKey('items', $body['items'][0]);
    }

    #[Test]
    public function rejectsAnInvalidRecentRunLimit(): void
    {
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::never())->method('recent');

        $response = $this->controller($runs, ActorContext::user(7))->list(new Request(['limit' => '101']));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    #[Test]
    public function returnsNotFoundWhenTheActorCannotReadTheRun(): void
    {
        $actor = ActorContext::user(7);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())->method('get')->with(self::RUN_ID, $actor)->willReturn(null);

        $response = $this->controller($runs, $actor)->get(self::RUN_ID);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    #[Test]
    public function serializesProgressAndItems(): void
    {
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('get')->willReturn($this->runFixture());

        $response = $this->controller($runs, ActorContext::user(7))->get(self::RUN_ID);
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(self::RUN_ID, $body['id']);
        self::assertSame(1, $body['processedCount']);
        self::assertSame(42, $body['items'][0]['targetId']);
        self::assertSame(['operationCount' => 2], $body['items'][0]['result']);
    }

    #[Test]
    public function rejectsCancellationOfATerminalRun(): void
    {
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('get')->willReturn($this->runFixture());
        $runs->method('requestCancellation')->willReturn(false);

        $response = $this->controller($runs, ActorContext::user(7))->cancel(self::RUN_ID);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    #[Test]
    public function finalizesAQueuedCancellationImmediately(): void
    {
        $actor = ActorContext::user(7);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('get')->willReturn($this->runFixture());
        $runs->expects(self::once())->method('requestCancellation')->with(self::RUN_ID, $actor)->willReturn(true);
        $runs->expects(self::once())->method('finish')->with(self::RUN_ID)->willReturn(OperationRunStatus::Cancelled);

        $response = $this->controller($runs, $actor)->cancel(self::RUN_ID);
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('cancelled', $body['status']);
    }

    #[Test]
    public function retriesFailedTargetsAsANewQueuedRun(): void
    {
        $actor = ActorContext::user(7);
        $original = $this->runFixture();
        $retry = $this->runFixture(self::RETRY_ID);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::exactly(2))->method('get')->willReturnOnConsecutiveCalls($original, $retry);
        $runs->expects(self::once())->method('retry')->with(self::RUN_ID, $actor)->willReturn(self::RETRY_ID);
        $executor = $this->createMock(OperationRunExecutorInterface::class);
        $executor->expects(self::once())->method('supports')->with(OperationRunKind::Organize)->willReturn(true);
        $executor->expects(self::once())
            ->method('execute')
            ->with(OperationRunKind::Organize, self::RETRY_ID, $retry, $actor)
            ->willReturn(new OperationRunExecution(OperationRunStatus::Queued, true));

        $response = $this->controller($runs, $actor, $executor)->retry(self::RUN_ID);
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        self::assertSame(self::RETRY_ID, $body['runId']);
        self::assertSame(self::RUN_ID, $body['retryOf']);
        self::assertSame('/pimcore-studio/api/asset-pilot/operations/runs/' . self::RETRY_ID, $body['statusUrl']);
    }

    #[Test]
    public function retriesDuplicateMergeRunsThroughTheMergeSaga(): void
    {
        $actor = ActorContext::user(7);
        $original = $this->runFixture();
        $original['kind'] = OperationRunKind::DuplicateMerge->value;
        $retry = $this->runFixture(self::RETRY_ID);
        $retry['kind'] = OperationRunKind::DuplicateMerge->value;

        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::exactly(2))->method('get')->willReturnOnConsecutiveCalls($original, $retry);
        $runs->expects(self::once())->method('retry')->with(self::RUN_ID, $actor)->willReturn(self::RETRY_ID);
        $executor = $this->createMock(OperationRunExecutorInterface::class);
        $executor->expects(self::once())->method('supports')->with(OperationRunKind::DuplicateMerge)->willReturn(true);
        $executor->expects(self::once())
            ->method('execute')
            ->with(OperationRunKind::DuplicateMerge, self::RETRY_ID, $retry, $actor)
            ->willReturn(new OperationRunExecution(
                OperationRunStatus::Completed,
                false,
                [new CopyDisposition(9, DispositionOutcome::Deleted)],
            ));

        $response = $this->controller($runs, $actor, $executor)->retry(self::RUN_ID);
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(self::RETRY_ID, $body['runId']);
        self::assertSame('completed', $body['status']);
        self::assertSame('deleted', $body['dispositions'][0]['outcome']);
    }

    #[Test]
    public function rejectsAnUnsupportedKindBeforeCloningARetry(): void
    {
        $run = $this->runFixture();
        $run['kind'] = 'unsupported';
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())->method('get')->willReturn($run);
        $runs->expects(self::never())->method('retry');

        $response = $this->controller($runs, ActorContext::user(7))->retry(self::RUN_ID);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    #[Test]
    public function dispatchFailureFailsTheClonedRun(): void
    {
        $actor = ActorContext::user(7);
        $original = $this->runFixture();
        $retry = $this->runFixture(self::RETRY_ID);
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::exactly(2))->method('get')->willReturnOnConsecutiveCalls($original, $retry);
        $runs->method('retry')->willReturn(self::RETRY_ID);
        $runs->expects(self::once())->method('fail')->with(self::RETRY_ID, 'The retry operation could not be dispatched.');
        $executor = $this->createMock(OperationRunExecutorInterface::class);
        $executor->method('supports')->willReturn(true);
        $executor->method('execute')->willThrowException(new \RuntimeException('transport unavailable'));

        $response = $this->controller($runs, $actor, $executor)->retry(self::RUN_ID);

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }

    private function controller(
        OperationRunStoreInterface $runs,
        ActorContext $actor,
        ?OperationRunExecutorInterface $executor = null,
    ): OperationRunsController {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn($actor);
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(
            static fn (string $route, array $parameters): string => sprintf(
                '/pimcore-studio/api/asset-pilot/operations/runs/%s',
                $parameters['id'],
            ),
        );

        return new OperationRunsController(
            $runs,
            $executor ?? $this->createMock(OperationRunExecutorInterface::class),
            $authorization,
            $urls,
        );
    }

    /** @return array<string, mixed> */
    private function runFixture(string $id = self::RUN_ID): array
    {
        return [
            'id' => $id,
            'kind' => 'organize',
            'actor_type' => 'user',
            'actor_user_id' => 7,
            'status' => 'partial',
            'total_count' => 1,
            'processed_count' => 1,
            'succeeded_count' => 0,
            'skipped_count' => 0,
            'blocked_count' => 0,
            'failed_count' => 1,
            'attempt' => 1,
            'retry_of' => null,
            'request_payload' => ['trigger' => 'api'],
            'error_message' => null,
            'created_at' => '2026-07-15 10:00:00',
            'started_at' => '2026-07-15 10:00:01',
            'updated_at' => '2026-07-15 10:00:02',
            'completed_at' => '2026-07-15 10:00:02',
            'items' => [[
                'item_key' => 'object:42',
                'target_type' => 'data_object',
                'target_id' => 42,
                'fingerprint' => 'fingerprint-42',
                'status' => 'failed',
                'attempts' => 1,
                'state_payload' => [],
                'result_payload' => ['operationCount' => 2],
                'error_message' => 'failed',
                'created_at' => '2026-07-15 10:00:00',
                'updated_at' => '2026-07-15 10:00:02',
                'completed_at' => '2026-07-15 10:00:02',
            ]],
        ];
    }
}
