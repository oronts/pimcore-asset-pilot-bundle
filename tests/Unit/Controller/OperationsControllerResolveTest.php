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
use Oronts\AssetPilotBundle\Service\ObjectSaveDrainInterface;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcher;
use Oronts\AssetPilotBundle\Service\OrganizePlanFingerprint;
use Oronts\AssetPilotBundle\Service\OrganizeRunDispatchCoordinator;
use Oronts\AssetPilotBundle\Service\Query\VisibleObjectSelectorInterface;
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
            $this->createMock(VisibleObjectSelectorInterface::class),
            $this->createMock(ObjectSaveDrainInterface::class),
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
    public function bulkPreviewShapesACursorResponseFromTheSelectorPage(): void
    {
        $selector = $this->createMock(VisibleObjectSelectorInterface::class);
        $selector->expects(self::once())->method('page')->with('Product', 5, 5)->willReturn([
            'objects' => [['id' => 1, 'key' => 'object-1', 'className' => 'Product']],
            'hasMore' => true,
            'truncated' => false,
        ]);

        $payload = json_decode((string) $this->objectPreviewController($selector)
            ->bulkPreview($this->post('{"className":"Product","page":2,"limit":5}'))->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertNull($payload['total']);
        self::assertNull($payload['pages']);
        self::assertSame(2, $payload['page']);
        self::assertTrue($payload['hasMore']);
        self::assertFalse($payload['truncated']);
        self::assertSame([1], array_column($payload['objects'], 'id'));
    }

    #[Test]
    public function bulkPreviewRejectsAnEmptyClassName(): void
    {
        $response = $this->objectPreviewController($this->createMock(VisibleObjectSelectorInterface::class))
            ->bulkPreview($this->post('{"className":"  ","page":1,"limit":2}'));
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('className is required', $payload['error']);
    }

    #[Test]
    public function organizeBulkRejectsADenialHeavyClassBeyondTheScanBudget(): void
    {
        $selector = $this->createMock(VisibleObjectSelectorInterface::class);
        $selector->method('resolveIds')->with('Product')->willReturn(['ids' => [], 'truncated' => true]);

        $response = $this->objectPreviewController($selector)->organizeBulk($this->post('{"className":"Product","async":true}'));
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('could not be resolved within the scan budget', $payload['error']);
    }

    #[Test]
    public function organizeBulkRejectsAClassResolvingBeyondTheBulkCap(): void
    {
        $selector = $this->createMock(VisibleObjectSelectorInterface::class);
        $selector->method('resolveIds')->with('Product')->willReturn(['ids' => range(1, 1001), 'truncated' => false]);

        $response = $this->objectPreviewController($selector)->organizeBulk($this->post('{"className":"Product","async":true}'));
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('resolves to more than', $payload['error']);
    }

    #[Test]
    public function organizeBulkRejectsAWhitespaceClassNameWithoutObjectIds(): void
    {
        $response = $this->objectPreviewController($this->createMock(VisibleObjectSelectorInterface::class))
            ->organizeBulk($this->post('{"className":"   ","async":true}'));
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('className or objectIds required', $payload['error']);
    }

    #[Test]
    public function replayRejectsANonStringRuleFilterAtTheEndpoint(): void
    {
        $response = $this->objectPreviewController($this->createMock(VisibleObjectSelectorInterface::class))
            ->replay($this->post('{"rule":["x"],"dryRun":true}'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('rule must be a string.', json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['error']);
    }

    #[Test]
    public function replayRejectsANonStringClassFilterAtTheEndpoint(): void
    {
        $response = $this->objectPreviewController($this->createMock(VisibleObjectSelectorInterface::class))
            ->replay($this->post('{"class":{"x":1},"dryRun":true}'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('class must be a string.', json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['error']);
    }

    private function objectPreviewController(VisibleObjectSelectorInterface $selector): OperationsController
    {
        return new OperationsController(
            $this->createMock(AssetOrganizer::class),
            $this->createMock(OrganizeDispatcher::class),
            $this->createMock(AuditQueryInterface::class),
            $this->createMock(RuleEngine::class),
            $this->createMock(AssetFieldExtractor::class),
            $this->createMock(FailureReplayService::class),
            $this->createMock(AssetReorganizer::class),
            $this->createMock(ElementAuthorization::class),
            $this->createMock(OperationRunStoreInterface::class),
            $this->createMock(ApplyPlanServiceInterface::class),
            new OrganizePlanFingerprint(),
            $this->createMock(ReviewedObjectOperationServiceInterface::class),
            new NullLogger(),
            new ApiDateFormatter(),
            $this->createMock(RunItemLease::class),
            new OperationResponseAssembler($this->createMock(UrlGeneratorInterface::class)),
            new OrganizeRunDispatchCoordinator($this->createMock(OrganizeDispatcher::class), $this->createMock(OperationRunStoreInterface::class), new NullLogger()),
            $selector,
            $this->createMock(ObjectSaveDrainInterface::class),
        );
    }
}
