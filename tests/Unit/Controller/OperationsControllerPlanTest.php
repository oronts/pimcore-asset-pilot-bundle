<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Api\Serialization\ApiDateFormatter;
use Oronts\AssetPilotBundle\Api\Serialization\OperationResponseAssembler;
use Oronts\AssetPilotBundle\Audit\AuditQueryInterface;
use Oronts\AssetPilotBundle\Controller\Api\OperationsController;
use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunKind;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\MoveOperation;
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
final class OperationsControllerPlanTest extends TestCase
{
    #[Test]
    public function singleApplyRequiresAndConsumesTheExactPreviewPlan(): void
    {
        $object = $this->object(42);
        $operation = $this->operation(42, 10);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->with($object, TriggerType::Api)->willReturn([$operation]);
        $fingerprint = (new OrganizePlanFingerprint())->forOperations($object, [$operation]);
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::once())->method('createRun')->with(
            [42],
            TriggerType::Api,
            ActorContext::user(7),
            [42 => $fingerprint],
        )->willReturn('run-1');
        $dispatcher->expects(self::once())->method('dispatchObject')->with(
            42,
            TriggerType::Api,
            ActorContext::user(7),
            'run-1',
            $fingerprint,
        )->willReturn('run-1');
        $controller = $this->controller([42 => $object], $organizer, $dispatcher);

        $preview = $controller->organize($this->request(['objectId' => 42, 'dryRun' => true]));
        $previewBody = $this->body($preview);
        self::assertSame(42, $previewBody['operations'][0]['objectId']);
        self::assertNotEmpty($previewBody['planToken']);

        $applyRequest = ['objectId' => 42, 'async' => true, 'planToken' => $previewBody['planToken']];
        self::assertSame(Response::HTTP_ACCEPTED, $controller->organize($this->request($applyRequest))->getStatusCode());
        self::assertSame(Response::HTTP_CONFLICT, $controller->organize($this->request($applyRequest))->getStatusCode());
    }
    #[Test]
    public function singleDispatchFailureFailsTheCreatedRun(): void
    {
        $object = $this->object(42);
        $operation = $this->operation(42, 10);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn([$operation]);
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->method('createRun')->willReturn('run-failed');
        $dispatcher->method('dispatchObject')->willThrowException(new \RuntimeException('broker unavailable'));
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())->method('fail')->with('run-failed', 'Organization could not be queued.');
        $controller = $this->controller([42 => $object], $organizer, $dispatcher, runs: $runs);

        $preview = $this->body($controller->organize($this->request(['objectId' => 42, 'dryRun' => true])));
        $response = $controller->organize($this->request([
            'objectId' => 42,
            'async' => true,
            'planToken' => $preview['planToken'],
        ]));

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertSame('run-failed', $this->body($response)['runId']);
    }


    #[Test]
    public function singleApplyRejectsAPlanWhenTheObjectChangedAfterPreview(): void
    {
        $modifiedAt = 100;
        $object = $this->createMock(Concrete::class);
        $object->method('getId')->willReturn(42);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getRealFullPath')->willReturn('/products/42');
        $object->method('getModificationDate')->willReturnCallback(static function () use (&$modifiedAt): int {
            return $modifiedAt;
        });
        $object->method('getType')->willReturn('object');
        $object->method('getVersionCount')->willReturn(1);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn([$this->operation(42, 10)]);
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatchObject');
        $controller = $this->controller([42 => $object], $organizer, $dispatcher);

        $preview = $this->body($controller->organize($this->request(['objectId' => 42, 'dryRun' => true])));
        $modifiedAt = 101;
        $response = $controller->organize($this->request([
            'objectId' => 42,
            'async' => true,
            'planToken' => $preview['planToken'],
        ]));

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    #[Test]
    public function applyWithoutAPlanTokenDoesNotEvenRecomputeThePreview(): void
    {
        $object = $this->object(42);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::never())->method('dryRun');
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatchObject');

        $response = $this->controller([42 => $object], $organizer, $dispatcher)->organize(
            $this->request(['objectId' => 42, 'async' => true]),
        );

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    #[Test]
    public function singleApplyRejectsFailedMutationPreflightBeforeQueueing(): void
    {
        $object = $this->object(42);
        $operation = $this->operation(42, 10);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn([$operation]);
        $organizer->expects(self::once())
            ->method('preflightApply')
            ->with([$operation])
            ->willReturn('Target path creation is not permitted.');
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatchObject');
        $controller = $this->controller([42 => $object], $organizer, $dispatcher);
        $preview = $this->body($controller->organize($this->request(['objectId' => 42, 'dryRun' => true])));

        $response = $controller->organize($this->request([
            'objectId' => 42,
            'async' => true,
            'planToken' => $preview['planToken'],
        ]));

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('Target path creation is not permitted.', $this->body($response)['error']);
    }

    #[Test]
    public function bulkApplyBindsTheExactObjectSetAndPropagatesEveryFingerprint(): void
    {
        $first = $this->object(1);
        $second = $this->object(2);
        $operations = [1 => $this->operation(1, 11), 2 => $this->operation(2, 12)];
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturnCallback(
            static fn (AbstractObject $object): array => [$operations[(int) $object->getId()]],
        );
        $fingerprints = new OrganizePlanFingerprint();
        $expected = [
            1 => $fingerprints->forOperations($first, [$operations[1]]),
            2 => $fingerprints->forOperations($second, [$operations[2]]),
        ];
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::once())->method('createRun')->with(
            [2, 1],
            TriggerType::Api,
            ActorContext::user(7),
            $expected,
        )->willReturn('run-bulk');
        $dispatcher->expects(self::once())->method('dispatchBulk')->with(
            [2, 1],
            TriggerType::Api,
            ActorContext::user(7),
            'run-bulk',
            $expected,
        )->willReturn('run-bulk');
        $controller = $this->controller([1 => $first, 2 => $second], $organizer, $dispatcher);

        $preview = $this->body($controller->organizeBulk($this->request([
            'objectIds' => [1, 2],
            'dryRun' => true,
        ])));
        $response = $controller->organizeBulk($this->request([
            'objectIds' => [2, 1],
            'async' => true,
            'planToken' => $preview['planToken'],
        ]));

        self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        self::assertCount(2, $preview['operations']);
    }

    #[Test]
    public function partialBulkDispatchFailureOnlyFailsUnacceptedItems(): void
    {
        $objects = [
            1 => $this->object(1),
            2 => $this->object(2),
            3 => $this->object(3),
        ];
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturnCallback(
            fn (AbstractObject $object): array => [$this->operation((int) $object->getId(), 10 + (int) $object->getId())],
        );
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->method('createRun')->willReturn('run-partial');
        $dispatchCount = 0;
        $dispatcher->expects(self::exactly(2))
            ->method('dispatchBulk')
            ->willReturnCallback(
                static function (array $ids) use (&$dispatchCount): string {
                    ++$dispatchCount;
                    if ($dispatchCount === 2) {
                        throw new \RuntimeException('broker unavailable');
                    }
                    self::assertSame([1, 2], $ids);

                    return 'run-partial';
                },
            );
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::never())->method('fail');
        $runs->expects(self::once())
            ->method('completeItem')
            ->with(
                'run-partial',
                'object:3',
                OperationRunItemStatus::Failed,
                [],
                'Bulk organization could not be queued.',
            )->willReturn(true);
        $runs->expects(self::once())->method('finish')->with('run-partial')->willReturn(OperationRunStatus::Running);
        $controller = $this->controller($objects, $organizer, $dispatcher, runs: $runs);

        $preview = $this->body($controller->organizeBulk($this->request([
            'objectIds' => [1, 2, 3],
            'dryRun' => true,
        ])));
        $response = $controller->organizeBulk($this->request([
            'objectIds' => [1, 2, 3],
            'async' => true,
            'planToken' => $preview['planToken'],
            'batchSize' => 2,
        ]));

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertSame('run-partial', $this->body($response)['runId']);
    }
    #[Test]
    public function bulkApplyRejectsAChangedSelection(): void
    {
        $first = $this->object(1);
        $second = $this->object(2);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturnCallback(
            fn (AbstractObject $object): array => [$this->operation((int) $object->getId(), 10 + (int) $object->getId())],
        );
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::never())->method('createRun');
        $dispatcher->expects(self::never())->method('dispatchBulk');
        $controller = $this->controller([1 => $first, 2 => $second], $organizer, $dispatcher);

        $preview = $this->body($controller->organizeBulk($this->request([
            'objectIds' => [1, 2],
            'dryRun' => true,
        ])));
        $response = $controller->organizeBulk($this->request([
            'objectIds' => [1],
            'async' => true,
            'planToken' => $preview['planToken'],
        ]));

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    #[Test]
    public function bulkApplyRejectsFailedMutationPreflightBeforeQueueing(): void
    {
        $object = $this->object(1);
        $operation = $this->operation(1, 11);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn([$operation]);
        $organizer->expects(self::once())
            ->method('preflightApply')
            ->with([$operation])
            ->willReturn('Source asset mutation is not permitted.');
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::never())->method('createRun');
        $dispatcher->expects(self::never())->method('dispatchBulk');
        $controller = $this->controller([1 => $object], $organizer, $dispatcher);
        $preview = $this->body($controller->organizeBulk($this->request([
            'objectIds' => [1],
            'dryRun' => true,
        ])));

        $response = $controller->organizeBulk($this->request([
            'objectIds' => [1],
            'async' => true,
            'planToken' => $preview['planToken'],
        ]));

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('Source asset mutation is not permitted.', $this->body($response)['error']);
    }

    #[Test]
    public function reorganizeRequiresTheExactPreviewPlanAndReturnsOneBatchRun(): void
    {
        $object = $this->object(42);
        $operation = $this->operation(42, 10);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->with($object, TriggerType::Api)->willReturn([$operation]);
        $fingerprint = (new OrganizePlanFingerprint())->forOperations($object, [$operation]);
        $reorganizer = $this->createMock(AssetReorganizer::class);
        $reorganizer->method('selectFolder')->with('/Staging', 25)->willReturn([
            'assetCount' => 3,
            'objectIds' => [42],
            'truncated' => false,
        ]);
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::once())->method('createRun')->with(
            [42],
            TriggerType::Api,
            ActorContext::user(7),
            [42 => $fingerprint],
            OperationRunKind::Reorganize,
            [
                'objectIds' => [42],
                'operations' => [$this->operationIdentity($operation)],
                'selector' => ['folder' => '/Staging', 'limit' => 25],
                'trigger' => TriggerType::Api->value,
            ],
        )->willReturn('reorganize-run');
        $dispatcher->expects(self::once())->method('dispatchBulk')->with(
            [42],
            TriggerType::Api,
            ActorContext::user(7),
            'reorganize-run',
            [42 => $fingerprint],
        )->willReturn('reorganize-run');
        $controller = $this->controller([42 => $object], $organizer, $dispatcher, reorganizer: $reorganizer);

        $previewResponse = $controller->reorganize($this->request([
            'folder' => '/Staging',
            'limit' => 25,
            'async' => true,
        ]));
        $preview = $this->body($previewResponse);
        self::assertTrue($preview['dryRun']);
        self::assertSame(3, $preview['assetsScanned']);
        self::assertNotEmpty($preview['planToken']);

        $apply = $controller->reorganize($this->request([
            'folder' => '/Staging',
            'limit' => 25,
            'dryRun' => false,
            'async' => true,
            'planToken' => $preview['planToken'],
        ]));

        self::assertSame(Response::HTTP_ACCEPTED, $apply->getStatusCode());
        self::assertSame('reorganize-run', $this->body($apply)['runId']);
        self::assertSame(Response::HTTP_CONFLICT, $controller->reorganize($this->request([
            'folder' => '/Staging',
            'limit' => 25,
            'dryRun' => false,
            'async' => true,
            'planToken' => $preview['planToken'],
        ]))->getStatusCode());
    }

    #[Test]
    public function replayRequiresTheExactPreviewPlanAndRejectsInvalidDates(): void
    {
        $object = $this->object(42);
        $operation = $this->operation(42, 10);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->with($object, TriggerType::Api)->willReturn([$operation]);
        $fingerprint = (new OrganizePlanFingerprint())->forOperations($object, [$operation]);
        $replay = $this->createMock(FailureReplayService::class);
        $replay->method('selectObjects')->willReturn([42]);
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::once())->method('createRun')->with(
            [42],
            TriggerType::Api,
            ActorContext::user(7),
            [42 => $fingerprint],
            OperationRunKind::Replay,
            [
                'objectIds' => [42],
                'operations' => [$this->operationIdentity($operation)],
                'selector' => ['filters' => ['rule_name' => 'failed-rule'], 'limit' => 10],
                'trigger' => TriggerType::Api->value,
            ],
        )->willReturn('replay-run');
        $dispatcher->expects(self::once())->method('dispatchBulk')->willReturn('replay-run');
        $controller = $this->controller([42 => $object], $organizer, $dispatcher, replay: $replay);

        self::assertSame(
            Response::HTTP_BAD_REQUEST,
            $controller->replay($this->request(['since' => 'not a date']))->getStatusCode(),
        );
        $preview = $this->body($controller->replay($this->request([
            'rule' => 'failed-rule',
            'limit' => 10,
            'async' => true,
        ])));
        self::assertTrue($preview['dryRun']);
        self::assertNotEmpty($preview['planToken']);

        self::assertSame(Response::HTTP_CONFLICT, $controller->replay($this->request([
            'rule' => 'another-rule',
            'limit' => 10,
            'dryRun' => false,
            'async' => true,
            'planToken' => $preview['planToken'],
        ]))->getStatusCode());

        $apply = $controller->replay($this->request([
            'rule' => 'failed-rule',
            'limit' => 10,
            'dryRun' => false,
            'async' => true,
            'planToken' => $preview['planToken'],
        ]));
        self::assertSame(Response::HTTP_ACCEPTED, $apply->getStatusCode());
        self::assertSame('replay-run', $this->body($apply)['runId']);
    }

    #[Test]
    public function replayNormalizesTheSinceCutoffToUtc(): void
    {
        $captured = null;
        $replay = $this->createMock(FailureReplayService::class);
        $replay->method('selectObjects')->willReturnCallback(function (array $filters) use (&$captured): array {
            $captured = $filters;

            return [];
        });
        $controller = $this->controller(
            [],
            $this->createMock(AssetOrganizer::class),
            $this->createMock(OrganizeDispatcher::class),
            replay: $replay,
        );

        $controller->replay($this->request(['since' => '2026-07-15 10:00:00+02:00']));

        self::assertSame('2026-07-15 08:00:00', $captured['since'] ?? null, 'the replay since cutoff must normalize to UTC to match UTC-stored created_at');
    }

    /** @param array<int, AbstractObject> $objects */
    private function controller(
        array $objects,
        AssetOrganizer $organizer,
        OrganizeDispatcher $dispatcher,
        ?FailureReplayService $replay = null,
        ?AssetReorganizer $reorganizer = null,
        ?OperationRunStoreInterface $runs = null,
    ): OperationsController {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn(ActorContext::user(7));
        $authorization->method('isAllowed')->willReturn(true);
        $runs ??= $this->createMock(OperationRunStoreInterface::class);
        $claims = new InMemoryApplyPlanClaimStore();
        $plans = new ApplyPlanService('test-secret', $claims);
        $fingerprints = new OrganizePlanFingerprint();
        $provider = $this->createMock(ActorContextProvider::class);
        $provider->method('current')->willReturn(ActorContext::system());
        $reviewed = $this->getMockBuilder(ReviewedObjectOperationService::class)
            ->setConstructorArgs([
                $organizer,
                $dispatcher,
                $authorization,
                new ActorContextStore($provider),
                $runs,
                $plans,
                $fingerprints,
                new NullLogger(),
                $this->createMock(RunItemLease::class),
            ])
            ->onlyMethods(['loadObject'])
            ->getMock();
        $reviewed->method('loadObject')->willReturnCallback(
            static fn (int $id): ?AbstractObject => $objects[$id] ?? null,
        );
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(
            static fn (string $route, array $parameters): string => sprintf(
                '/pimcore-studio/api/asset-pilot/operations/runs/%s',
                $parameters['id'],
            ),
        );
        $runCoordinator = new OrganizeRunDispatchCoordinator($dispatcher, $runs, new NullLogger());

        return new class (
            $organizer,
            $dispatcher,
            $this->createMock(AuditQueryInterface::class),
            $this->createMock(RuleEngineInterface::class),
            $this->createMock(AssetFieldExtractorInterface::class),
            $replay ?? $this->createMock(FailureReplayService::class),
            $reorganizer ?? $this->createMock(AssetReorganizer::class),
            $authorization,
            $runs,
            $plans,
            $fingerprints,
            $reviewed,
            new NullLogger(),
            $this->createMock(RunItemLease::class),
            new OperationResponseAssembler($urls),
            $runCoordinator,
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
                NullLogger $logger,
                RunItemLease $runItemLease,
                OperationResponseAssembler $responses,
                OrganizeRunDispatchCoordinator $runCoordinator,
                private readonly array $objects,
            ) {
                parent::__construct(
                    $organizer, $dispatcher, $audit, $rules, $fields, $replay, $reorganizer, $authorization, $runs, $plans, $fingerprints, $reviewed, $logger, new ApiDateFormatter(), $runItemLease, $responses, $runCoordinator,
                );
            }

            protected function loadObject(int $id): ?AbstractObject
            {
                return $this->objects[$id] ?? null;
            }
        };
    }

    private function object(int $id, int $modifiedAt = 100): Concrete
    {
        $object = $this->createMock(Concrete::class);
        $object->method('getId')->willReturn($id);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getRealFullPath')->willReturn('/products/' . $id);
        $object->method('getModificationDate')->willReturn($modifiedAt);
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

    /** @return array<string, int|string|null> */
    private function operationIdentity(MoveOperation $operation): array
    {
        return [
            'assetId' => $operation->assetId,
            'error' => $operation->errorMessage,
            'objectClass' => $operation->objectClass,
            'objectId' => $operation->objectId,
            'ruleName' => $operation->ruleName,
            'sourcePath' => $operation->sourcePath,
            'status' => $operation->status->value,
            'targetPath' => $operation->targetPath,
        ];
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
