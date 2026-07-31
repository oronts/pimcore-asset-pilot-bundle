<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Api\Serialization\ApiDateFormatter;
use Oronts\AssetPilotBundle\Api\Serialization\OperationResponseAssembler;
use Oronts\AssetPilotBundle\Audit\AuditQueryInterface;
use Oronts\AssetPilotBundle\Controller\Api\OperationsController;
use Oronts\AssetPilotBundle\Engine\RuleEngine;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractor;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\AssetReorganizer;
use Oronts\AssetPilotBundle\Service\FailureReplayService;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcher;
use Oronts\AssetPilotBundle\Service\OrganizePlanFingerprint;
use Oronts\AssetPilotBundle\Service\OrganizeRunDispatchCoordinator;
use Oronts\AssetPilotBundle\Service\ReviewedObjectOperationServiceInterface;
use Oronts\AssetPilotBundle\Service\RunItemLease;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(OperationsController::class)]
class OperationsControllerResolveTest extends TestCase
{
    private function controller(?AbstractObject $object): object
    {
        $c = new class (
            $organizer = $this->createMock(AssetOrganizer::class),
            $this->createMock(OrganizeDispatcher::class),
            $this->createMock(AuditQueryInterface::class),
            $this->createMock(RuleEngine::class),
            $this->createMock(AssetFieldExtractor::class),
            $this->createMock(FailureReplayService::class),
            $this->createMock(AssetReorganizer::class),
            $authorization = $this->createMock(ElementAuthorization::class),
            $this->createMock(OperationRunStoreInterface::class),
            $this->createMock(ApplyPlanServiceInterface::class),
            new OrganizePlanFingerprint(),
            $this->createMock(ReviewedObjectOperationServiceInterface::class),
            new NullLogger(),
            new ApiDateFormatter(),
            $this->createMock(RunItemLease::class),
            new OperationResponseAssembler($this->createMock(UrlGeneratorInterface::class)),
            new OrganizeRunDispatchCoordinator($this->createMock(OrganizeDispatcher::class), $this->createMock(OperationRunStoreInterface::class), new NullLogger()),
        ) extends OperationsController {
            public ?AbstractObject $stub = null;

            protected function loadObject(int $id): ?AbstractObject
            {
                return $this->stub;
            }

            public function resolve(Request $request): array|JsonResponse
            {
                return $this->resolveObjectFromBody($request);
            }
        };
        $authorization->method('isAllowed')->willReturn(true);
        $c->stub = $object;

        return $c;
    }

    private function post(string $body): Request
    {
        return Request::create('/', 'POST', [], [], [], [], $body);
    }

    #[Test]
    public function rejectsInvalidJson(): void
    {
        $response = $this->controller(null)->resolve($this->post('{not json'));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    #[Test]
    public function rejectsScalarJsonBody(): void
    {
        $response = $this->controller(null)->resolve($this->post('123'));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    #[Test]
    public function rejectsMissingObjectId(): void
    {
        $response = $this->controller(null)->resolve($this->post('{"foo":1}'));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('objectId', (string) $response->getContent());
    }

    #[Test]
    public function returnsNotFoundWhenObjectMissing(): void
    {
        $response = $this->controller(null)->resolve($this->post('{"objectId":42}'));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    #[Test]
    public function returnsObjectAndBodyOnSuccess(): void
    {
        $object = $this->createMock(AbstractObject::class);
        $response = $this->controller($object)->resolve($this->post('{"objectId":42,"dryRun":true}'));

        self::assertIsArray($response);
        self::assertSame($object, $response[0]);
        self::assertTrue($response[1]['dryRun']);
    }

    #[Test]
    public function bulkPreviewPaginatesOnlyVisibleObjectsWithoutAnExactTotal(): void
    {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (AbstractObject $object): bool => $object->getId() !== 2,
        );
        $controller = $this->objectPreviewController($authorization, $this->objectMocks([1, 2, 3]), [1, 2, 3]);

        $payload = json_decode((string) $controller->bulkPreview($this->post('{"className":"Product","page":1,"limit":1}'))->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertNull($payload['total']);
        self::assertNull($payload['pages']);
        self::assertTrue($payload['hasMore']);
        self::assertFalse($payload['truncated']);
        self::assertSame([1], array_column($payload['objects'], 'id'));
    }

    #[Test]
    public function bulkPreviewStopsAtTheCandidateBudgetAndReportsTruncated(): void
    {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(false);
        $ids = range(1, 100);
        $controller = $this->objectPreviewController($authorization, $this->objectMocks($ids), $ids, 10);

        $payload = json_decode((string) $controller->bulkPreview($this->post('{"className":"Product","page":1,"limit":5}'))->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([], $payload['objects']);
        self::assertFalse($payload['hasMore']);
        self::assertTrue($payload['truncated']);
    }

    #[Test]
    public function bulkPreviewHasMoreReflectsAnAuthorizedSurplusPastThePage(): void
    {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);

        $exact = $this->objectPreviewController($authorization, $this->objectMocks([1, 2]), [1, 2]);
        $payload = json_decode((string) $exact->bulkPreview($this->post('{"className":"Product","page":1,"limit":2}'))->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([1, 2], array_column($payload['objects'], 'id'));
        self::assertFalse($payload['hasMore']);

        $surplus = $this->objectPreviewController($authorization, $this->objectMocks([1, 2, 3]), [1, 2, 3]);
        $payload = json_decode((string) $surplus->bulkPreview($this->post('{"className":"Product","page":1,"limit":2}'))->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([1, 2], array_column($payload['objects'], 'id'));
        self::assertTrue($payload['hasMore']);
    }

    #[Test]
    public function organizeBulkRejectsADenialHeavyClassBeyondTheScanBudget(): void
    {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(false);
        $ids = range(1, 100);
        $controller = $this->objectPreviewController($authorization, $this->objectMocks($ids), $ids, 10);

        $response = $controller->organizeBulk($this->post('{"className":"Product","async":true}'));
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('could not be resolved within the scan budget', $payload['error']);
    }

    #[Test]
    public function bulkPreviewRejectsAnEmptyClassName(): void
    {
        $controller = $this->objectPreviewController($this->createMock(ElementAuthorization::class), [], []);

        $response = $controller->bulkPreview($this->post('{"className":"  ","page":1,"limit":2}'));
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('className is required', $payload['error']);
    }

    #[Test]
    public function organizeBulkRejectsAWhitespaceClassNameWithoutObjectIds(): void
    {
        $controller = $this->objectPreviewController($this->createMock(ElementAuthorization::class), [], []);

        $response = $controller->organizeBulk($this->post('{"className":"   ","async":true}'));
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('className or objectIds required', $payload['error']);
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, AbstractObject>
     */
    private function objectMocks(array $ids): array
    {
        $map = [];
        foreach ($ids as $id) {
            $object = $this->createMock(AbstractObject::class);
            $object->method('getId')->willReturn($id);
            $object->method('getKey')->willReturn('object-' . $id);
            $map[$id] = $object;
        }

        return $map;
    }

    /**
     * @param array<int, AbstractObject> $objectsById
     * @param list<int>                  $windowIds
     */
    private function objectPreviewController(ElementAuthorization $authorization, array $objectsById, array $windowIds, int $budget = 5000): OperationsController
    {
        return new class (
            $this->createMock(AssetOrganizer::class),
            $this->createMock(OrganizeDispatcher::class),
            $this->createMock(AuditQueryInterface::class),
            $this->createMock(RuleEngine::class),
            $this->createMock(AssetFieldExtractor::class),
            $this->createMock(FailureReplayService::class),
            $this->createMock(AssetReorganizer::class),
            $authorization,
            $this->createMock(OperationRunStoreInterface::class),
            $this->createMock(ApplyPlanServiceInterface::class),
            new OrganizePlanFingerprint(),
            $this->createMock(ReviewedObjectOperationServiceInterface::class),
            new NullLogger(),
            $this->createMock(RunItemLease::class),
            new OperationResponseAssembler($this->createMock(UrlGeneratorInterface::class)),
            new OrganizeRunDispatchCoordinator($this->createMock(OrganizeDispatcher::class), $this->createMock(OperationRunStoreInterface::class), new NullLogger()),
            $objectsById,
            $windowIds,
            $budget,
        ) extends OperationsController {
            /**
             * @param array<int, AbstractObject> $objectsById
             * @param list<int>                  $windowIds
             */
            public function __construct(
                AssetOrganizer $organizer,
                OrganizeDispatcher $dispatcher,
                AuditQueryInterface $audit,
                RuleEngine $rules,
                AssetFieldExtractor $fields,
                FailureReplayService $replay,
                AssetReorganizer $reorganizer,
                ElementAuthorization $authorization,
                OperationRunStoreInterface $runs,
                ApplyPlanServiceInterface $plans,
                OrganizePlanFingerprint $fingerprints,
                ReviewedObjectOperationServiceInterface $reviewed,
                NullLogger $logger,
                RunItemLease $runItemLease,
                OperationResponseAssembler $responses,
                OrganizeRunDispatchCoordinator $runCoordinator,
                private readonly array $objectsById,
                private readonly array $windowIds,
                int $budget,
            ) {
                parent::__construct($organizer, $dispatcher, $audit, $rules, $fields, $replay, $reorganizer, $authorization, $runs, $plans, $fingerprints, $reviewed, $logger, new ApiDateFormatter(), $runItemLease, $responses, $runCoordinator, objectScanBudget: $budget);
            }

            protected function listObjectIds(string $className, int $offset, int $limit): array
            {
                return array_slice($this->windowIds, $offset, $limit);
            }

            protected function loadObject(int $id): ?AbstractObject
            {
                return $this->objectsById[$id] ?? null;
            }
        };
    }
}
