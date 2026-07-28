<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Api\Serialization\ApiDateFormatter;
use Oronts\AssetPilotBundle\Api\Serialization\OperationResponseAssembler;
use Oronts\AssetPilotBundle\Audit\AuditQueryInterface;
use Oronts\AssetPilotBundle\Controller\Api\OperationsController;
use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\BulkOrganizeReport;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Model\OperationResult;
use Oronts\AssetPilotBundle\Security\ActorContextProvider;
use Oronts\AssetPilotBundle\Security\ActorContextStore;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\ApplyPlanService;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractorInterface;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\AssetReorganizer;
use Oronts\AssetPilotBundle\Service\FailureReplayService;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcher;
use Oronts\AssetPilotBundle\Service\OrganizePlanFingerprint;
use Oronts\AssetPilotBundle\Service\OrganizeRunDispatchCoordinator;
use Oronts\AssetPilotBundle\Service\ReviewedObjectOperationService;
use Oronts\AssetPilotBundle\Service\RunItemLease;
use Oronts\AssetPilotBundle\Tests\Support\InMemoryApplyPlanClaimStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(OperationsController::class)]
final class OperationsControllerSyncRunTest extends TestCase
{
    #[Test]
    public function syncSingleCompletesUnderTheItemLeaseAndFinishesTheRun(): void
    {
        $op = $this->operation(42, 10);
        $organizer = $this->organizer([$op]);
        $organizer->expects(self::once())->method('organizeWithHeartbeat')->willReturn([OperationResult::success($op)]);
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $lease->expects(self::once())->method('complete')
            ->with('run-sync', 'object:42', OperationRunItemStatus::Completed, ['operationCount' => 1])->willReturn(true);
        $lease->expects(self::once())->method('release')->with('run-sync', 'object:42');
        $runs = $this->runs();
        $runs->expects(self::once())->method('finish')->with('run-sync')->willReturn(OperationRunStatus::Completed);
        $runs->expects(self::never())->method('fail');

        $response = $this->applySingle($organizer, $lease, $runs);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('run-sync', $this->body($response)['runId']);
    }

    #[Test]
    public function syncSingleReturnsConflictWhenTheItemCannotBeClaimed(): void
    {
        $organizer = $this->organizer([$this->operation(42, 10)]);
        $organizer->expects(self::never())->method('organizeWithHeartbeat');
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(false);
        $runs = $this->runs();
        $runs->expects(self::never())->method('finish');

        $response = $this->applySingle($organizer, $lease, $runs);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertStringContainsString('could not be claimed', $this->body($response)['error']);
    }

    #[Test]
    public function syncSingleReturnsConflictWhenTheLeaseIsLostAtCompletion(): void
    {
        $op = $this->operation(42, 10);
        $organizer = $this->organizer([$op]);
        $organizer->method('organizeWithHeartbeat')->willReturn([OperationResult::success($op)]);
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $lease->method('complete')->willReturn(false);
        $lease->expects(self::once())->method('release');
        $runs = $this->runs();
        $runs->expects(self::never())->method('finish');

        $response = $this->applySingle($organizer, $lease, $runs);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertStringContainsString('could not be durably recorded', $this->body($response)['error']);
    }

    #[Test]
    public function syncSingleSkipsAndConflictsWhenTheObjectChangedAfterPreview(): void
    {
        $organizer = $this->organizer([$this->operation(42, 10)]);
        $organizer->method('organizeWithHeartbeat')->willThrowException(new StaleApplyPlanException(42));
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $lease->expects(self::once())->method('complete')
            ->with('run-sync', 'object:42', OperationRunItemStatus::Skipped, [], self::anything())->willReturn(true);
        $lease->expects(self::once())->method('release');
        $runs = $this->runs();
        $runs->expects(self::once())->method('finish')->willReturn(OperationRunStatus::Completed);
        $runs->expects(self::never())->method('fail');

        $response = $this->applySingle($organizer, $lease, $runs);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertStringContainsString('changed after preview', $this->body($response)['error']);
    }

    #[Test]
    public function syncSingleFailsTheRunAndReturns500WhenOrganizationThrows(): void
    {
        $organizer = $this->organizer([$this->operation(42, 10)]);
        $organizer->method('organizeWithHeartbeat')->willThrowException(new \RuntimeException('boom'));
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('start')->willReturn(true);
        $lease->expects(self::once())->method('complete')
            ->with('run-sync', 'object:42', OperationRunItemStatus::Failed, [], 'Organization failed.')->willReturn(true);
        $lease->expects(self::once())->method('release');
        $runs = $this->runs();
        $runs->expects(self::once())->method('fail')->with('run-sync', 'Organization failed.');
        $runs->expects(self::never())->method('finish');

        $response = $this->applySingle($organizer, $lease, $runs);

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertSame('run-sync', $this->body($response)['runId']);
    }

    #[Test]
    public function syncBulkCompletesAndFinishesTheRun(): void
    {
        $op = $this->operation(1, 11);
        $organizer = $this->organizer([$op]);
        $organizer->expects(self::once())->method('organizeBulkDetailed')->willReturn(new BulkOrganizeReport([], []));
        $lease = $this->createMock(RunItemLease::class);
        $runs = $this->runs();
        $runs->expects(self::once())->method('finish')->with('run-sync')->willReturn(OperationRunStatus::Completed);
        $runs->expects(self::never())->method('fail');

        $response = $this->applyBulk($organizer, $lease, $runs);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('run-sync', $this->body($response)['runId']);
    }

    #[Test]
    public function syncBulkFailsEveryItemAndReturns500WhenOrganizationThrows(): void
    {
        $op = $this->operation(1, 11);
        $organizer = $this->organizer([$op]);
        $organizer->method('organizeBulkDetailed')->willThrowException(new \RuntimeException('boom'));
        $lease = $this->createMock(RunItemLease::class);
        $lease->method('token')->willReturnMap([['run-sync', 'object:1', 'tok']]);
        $lease->expects(self::once())->method('complete')
            ->with('run-sync', 'object:1', OperationRunItemStatus::Failed, [], 'Bulk organization failed.')->willReturn(true);
        $runs = $this->runs();
        $runs->expects(self::once())->method('fail')->with('run-sync', 'Bulk organization failed.');
        $runs->expects(self::never())->method('finish');

        $response = $this->applyBulk($organizer, $lease, $runs);

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertSame('run-sync', $this->body($response)['runId']);
    }

    private function applySingle(AssetOrganizer $organizer, RunItemLease $lease, OperationRunStoreInterface $runs): Response
    {
        $controller = $this->controller([42 => $this->object(42)], $organizer, $lease, $runs);
        $token = $this->body($controller->organize($this->request(['objectId' => 42, 'dryRun' => true])))['planToken'];

        return $controller->organize($this->request(['objectId' => 42, 'planToken' => $token]));
    }

    private function applyBulk(AssetOrganizer $organizer, RunItemLease $lease, OperationRunStoreInterface $runs): Response
    {
        $controller = $this->controller([1 => $this->object(1)], $organizer, $lease, $runs);
        $token = $this->body($controller->organizeBulk($this->request(['objectIds' => [1], 'dryRun' => true])))['planToken'];

        return $controller->organizeBulk($this->request(['objectIds' => [1], 'async' => false, 'planToken' => $token]));
    }

    /** @param list<MoveOperation> $dryRun */
    private function organizer(array $dryRun): AssetOrganizer
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn($dryRun);
        $organizer->method('preflightApply')->willReturn(null);

        return $organizer;
    }

    private function runs(): OperationRunStoreInterface
    {
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->method('isCancellationRequested')->willReturn(false);

        return $runs;
    }

    /** @param array<int, AbstractObject> $objects */
    private function controller(array $objects, AssetOrganizer $organizer, RunItemLease $lease, OperationRunStoreInterface $runs): OperationsController
    {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn(ActorContext::user(7));
        $authorization->method('isAllowed')->willReturn(true);
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->method('createRun')->willReturn('run-sync');
        $plans = new ApplyPlanService('test-secret', new InMemoryApplyPlanClaimStore());
        $fingerprints = new OrganizePlanFingerprint();
        $provider = $this->createMock(ActorContextProvider::class);
        $provider->method('current')->willReturn(ActorContext::system());
        $reviewed = $this->getMockBuilder(ReviewedObjectOperationService::class)
            ->setConstructorArgs([$organizer, $dispatcher, $authorization, new ActorContextStore($provider), $runs, $plans, $fingerprints, new NullLogger(), $this->createMock(RunItemLease::class)])
            ->onlyMethods(['loadObject'])
            ->getMock();
        $responses = new OperationResponseAssembler($this->createMock(UrlGeneratorInterface::class));
        $coordinator = new OrganizeRunDispatchCoordinator($dispatcher, $runs, new NullLogger());

        return new class (
            $organizer,
            $dispatcher,
            $this->createMock(AuditQueryInterface::class),
            $this->createMock(RuleEngineInterface::class),
            $this->createMock(AssetFieldExtractorInterface::class),
            $this->createMock(FailureReplayService::class),
            $this->createMock(AssetReorganizer::class),
            $authorization,
            $runs,
            $plans,
            $fingerprints,
            $reviewed,
            $lease,
            $responses,
            $coordinator,
            $objects,
        ) extends OperationsController {
            /** @param array<int, AbstractObject> $objects */
            public function __construct(
                AssetOrganizer $organizer,
                OrganizeDispatcher $dispatcher,
                AuditQueryInterface $audit,
                RuleEngineInterface $rules,
                AssetFieldExtractorInterface $fields,
                FailureReplayService $replay,
                AssetReorganizer $reorganizer,
                ElementAuthorization $authorization,
                OperationRunStoreInterface $runs,
                ApplyPlanService $plans,
                OrganizePlanFingerprint $fingerprints,
                ReviewedObjectOperationService $reviewed,
                RunItemLease $runItemLease,
                OperationResponseAssembler $responses,
                OrganizeRunDispatchCoordinator $runCoordinator,
                private readonly array $objects,
            ) {
                parent::__construct(
                    $organizer, $dispatcher, $audit, $rules, $fields, $replay, $reorganizer, $authorization, $runs, $plans, $fingerprints, $reviewed, new NullLogger(), new ApiDateFormatter(), $runItemLease, $responses, $runCoordinator,
                );
            }

            protected function loadObject(int $id): ?AbstractObject
            {
                return $this->objects[$id] ?? null;
            }
        };
    }

    private function object(int $id): Concrete
    {
        $object = $this->createMock(Concrete::class);
        $object->method('getId')->willReturn($id);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getRealFullPath')->willReturn('/products/' . $id);
        $object->method('getModificationDate')->willReturn(100);
        $object->method('getType')->willReturn('object');
        $object->method('getVersionCount')->willReturn(1);

        return $object;
    }

    private function operation(int $objectId, int $assetId): MoveOperation
    {
        return new MoveOperation(
            assetId: $assetId,
            sourcePath: '/incoming/' . $assetId . '.jpg',
            targetPath: '/organized/' . $assetId . '.jpg',
            objectId: $objectId,
            objectClass: 'Product',
            ruleName: 'product-assets',
            status: OperationStatus::Pending,
            triggerType: TriggerType::Api,
        );
    }

    /** @param array<string, mixed> $body */
    private function request(array $body): Request
    {
        return Request::create('/', 'POST', [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function body(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
