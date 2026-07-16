<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Audit\AuditLogger;
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
use Oronts\AssetPilotBundle\Service\ReviewedObjectOperationServiceInterface;
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
            $this->createMock(AuditLogger::class),
            $this->createMock(RuleEngine::class),
            $this->createMock(AssetFieldExtractor::class),
            $this->createMock(FailureReplayService::class),
            $this->createMock(AssetReorganizer::class),
            $authorization = $this->createMock(ElementAuthorization::class),
            $this->createMock(OperationRunStoreInterface::class),
            $this->createMock(ApplyPlanServiceInterface::class),
            new OrganizePlanFingerprint(),
            $this->createMock(ReviewedObjectOperationServiceInterface::class),
            $this->createMock(UrlGeneratorInterface::class),
            new NullLogger(),
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
    public function bulkPreviewPaginatesAndCountsOnlyVisibleObjects(): void
    {
        $objects = [];
        foreach ([1, 2, 3] as $id) {
            $object = $this->createMock(AbstractObject::class);
            $object->method('getId')->willReturn($id);
            $object->method('getKey')->willReturn('object-' . $id);
            $objects[] = $object;
        }
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (AbstractObject $object): bool => $object->getId() !== 2,
        );
        $controller = new class (
            $organizer = $this->createMock(AssetOrganizer::class),
            $this->createMock(OrganizeDispatcher::class),
            $this->createMock(AuditLogger::class),
            $this->createMock(RuleEngine::class),
            $this->createMock(AssetFieldExtractor::class),
            $this->createMock(FailureReplayService::class),
            $this->createMock(AssetReorganizer::class),
            $authorization,
            $this->createMock(OperationRunStoreInterface::class),
            $this->createMock(ApplyPlanServiceInterface::class),
            new OrganizePlanFingerprint(),
            $this->createMock(ReviewedObjectOperationServiceInterface::class),
            $this->createMock(UrlGeneratorInterface::class),
            new NullLogger(),
            $objects,
        ) extends OperationsController {
            /** @param list<AbstractObject> $objects */
            public function __construct(
                AssetOrganizer $organizer,
                OrganizeDispatcher $dispatcher,
                AuditLogger $audit,
                RuleEngine $rules,
                AssetFieldExtractor $fields,
                FailureReplayService $replay,
                AssetReorganizer $reorganizer,
                ElementAuthorization $authorization,
                OperationRunStoreInterface $runs,
                ApplyPlanServiceInterface $plans,
                OrganizePlanFingerprint $fingerprints,
                ReviewedObjectOperationServiceInterface $reviewed,
                UrlGeneratorInterface $urlGenerator,
                NullLogger $logger,
                private readonly array $objects,
            ) {
                parent::__construct($organizer, $dispatcher, $audit, $rules, $fields, $replay, $reorganizer, $authorization, $runs, $plans, $fingerprints, $reviewed, $urlGenerator, $logger);
            }

            protected function objectsForClass(string $className): \Generator
            {
                yield from $this->objects;
            }
        };
        $request = $this->post('{"className":"Product","page":1,"limit":1}');

        $response = $controller->bulkPreview($request);
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(2, $payload['total']);
        self::assertSame(2, $payload['pages']);
        self::assertSame(1, $payload['objects'][0]['id']);
    }
}
