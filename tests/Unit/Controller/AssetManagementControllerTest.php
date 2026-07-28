<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Controller\Api\AssetManagementController;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetMetadataMutationService;
use Oronts\AssetPilotBundle\Service\AssetPropertyService;
use Oronts\AssetPilotBundle\Service\AssetSearchServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetZipServiceInterface;
use Oronts\AssetPilotBundle\Service\ZipDownloadTokenStore;
use Oronts\AssetPilotBundle\Zip\ZipBuildOptions;
use Oronts\AssetPilotBundle\Zip\ZipBuildResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Element\Tag;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(AssetManagementController::class)]
final class AssetManagementControllerTest extends TestCase
{
    #[Test]
    public function availableTagsPaginatesAndEscapesSearch(): void
    {
        $tag = (new Tag())
            ->setId(7)
            ->setName('Campaign')
            ->setParentId(0);
        $listing = new RecordingTagListing([$tag], 3);
        $controller = $this->controllerWithTagListing($listing);
        $request = Request::create('/assets/tags', 'GET', [
            'page' => 2,
            'limit' => 2,
            'q' => 'foo%_bar',
        ]);

        $response = $controller->availableTags($request);
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame([
            'items' => [[
                'id' => 7,
                'name' => 'Campaign',
                'parentId' => 0,
                'path' => '/7/',
            ]],
            'total' => 3,
            'page' => 2,
            'limit' => 2,
            'pages' => 2,
        ], $data);
        self::assertSame(["(name LIKE ? ESCAPE '!' OR CONCAT(idPath, id, '/') LIKE ? ESCAPE '!')", ['%foo!%!_bar%', '%foo!%!_bar%']], $listing->recordedCondition);
        self::assertSame(['name', 'id'], $listing->recordedOrderKey);
        self::assertSame(['asc', 'asc'], $listing->recordedOrder);
        self::assertSame(2, $listing->recordedOffset);
        self::assertSame(2, $listing->recordedLimit);
    }

    #[Test]
    public function availableTagsClampsLimit(): void
    {
        $listing = new RecordingTagListing([], 0);
        $controller = $this->controllerWithTagListing($listing);
        $request = Request::create('/assets/tags', 'GET', ['page' => -1, 'limit' => 999]);

        $response = $controller->availableTags($request);
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(1, $data['page']);
        self::assertSame(200, $data['limit']);
        self::assertSame(0, $data['pages']);
        self::assertNull($listing->recordedCondition);
        self::assertSame(0, $listing->recordedOffset);
        self::assertSame(200, $listing->recordedLimit);
    }

    #[Test]
    public function availableTagsReturnsStructuredError(): void
    {
        $listing = new RecordingTagListing([], 0, new \RuntimeException('database unavailable'));
        $controller = $this->controllerWithTagListing($listing);

        $response = $controller->availableTags(Request::create('/assets/tags'));
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertSame('Failed to load tags.', $data['error']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $data['reference']);
    }

    #[Test]
    public function bulkPropertyRejectsStructuredDataBeforeMutation(): void
    {
        $properties = $this->createMock(AssetPropertyService::class);
        $properties->expects(self::never())->method('bulkSetProperty');
        $controller = new AssetManagementController(
            $this->createMock(AssetSearchServiceInterface::class),
            $properties,
            new NullLogger(),
            new EventDispatcher(),
            $this->createMock(AssetZipServiceInterface::class),
            $this->createMock(ElementAuthorization::class),
            new ZipDownloadTokenStore(new ArrayAdapter(), new LockFactory(new InMemoryStore())),
            $this->createMock(ApplyPlanServiceInterface::class),
            $this->createMock(AssetMetadataMutationService::class),
        );
        $request = Request::create('/', 'POST', [], [], [], [], json_encode([
            'assetIds' => [1],
            'name' => 'source',
            'type' => 'text',
            'data' => ['unexpected' => 'object'],
        ], JSON_THROW_ON_ERROR));

        $response = $controller->bulkProperty($request);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('data must be', (string) $response->getContent());
    }

    #[Test]
    public function bulkTagReturnsSuccessWithObserverWarningAfterAssignment(): void
    {
        $actor = ActorContext::user(7);
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn($actor);
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $metadata = $this->createMock(AssetMetadataMutationService::class);
        $plan = new ApplyPlan(
            'asset-bulk-tag',
            $actor,
            ['assetIds' => [1, 2], 'replace' => true, 'tagIds' => [7]],
            ['version' => 1],
            [
                new ApplyPlanTarget('asset:1', 'tag-fingerprint-1'),
                new ApplyPlanTarget('asset:2', 'tag-fingerprint-2'),
            ],
        );
        $metadata->expects(self::once())
            ->method('tagPlan')
            ->with($actor, [1, 2], [7], true)
            ->willReturn($plan);
        $plans->expects(self::once())
            ->method('claim')
            ->with('fresh-plan', $plan)
            ->willReturn(ApplyPlanStatus::Claimed);
        $metadata->expects(self::once())
            ->method('applyTags')
            ->with(
                [1, 2],
                [7],
                true,
                ['asset:1' => 'tag-fingerprint-1', 'asset:2' => 'tag-fingerprint-2'],
            );

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(AssetPilotEvents::ASSETS_TAGGED, static function (): never {
            throw new \RuntimeException('tag observer failed');
        });

        $controller = new class (
            $this->createMock(AssetSearchServiceInterface::class),
            $this->createMock(AssetPropertyService::class),
            new NullLogger(),
            $dispatcher,
            $this->createMock(AssetZipServiceInterface::class),
            $authorization,
            new ZipDownloadTokenStore(new ArrayAdapter(), new LockFactory(new InMemoryStore())),
            $plans,
            $metadata,
        ) extends AssetManagementController {
            protected function firstForbiddenAsset(array $assetIds): ?int
            {
                return null;
            }
        };

        $request = Request::create('/', 'POST', [], [], [], [], json_encode([
            'assetIds' => [2, 1],
            'tagIds' => [7],
            'replace' => true,
            'planToken' => 'fresh-plan',
        ], JSON_THROW_ON_ERROR));

        $response = $controller->bulkTag($request);
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(2, $data['tagged']);
        self::assertSame(0, $data['failed']);
        self::assertSame(['Asset-tag observer delivery failed.'], $data['observerWarnings']);
        self::assertFalse($data['dryRun']);
        self::assertNull($data['planToken']);
        self::assertSame(2, $data['eligible']);
    }

    #[Test]
    public function bulkTagDryRunIssuesTheExactSortedPlanWithoutMutation(): void
    {
        $actor = ActorContext::user(7);
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn($actor);
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $metadata = $this->createMock(AssetMetadataMutationService::class);
        $plan = new ApplyPlan(
            'asset-bulk-tag',
            $actor,
            ['assetIds' => [1, 2], 'replace' => true, 'tagIds' => [7, 9]],
            ['version' => 1],
            [
                new ApplyPlanTarget('asset:1', 'tag-fingerprint-1'),
                new ApplyPlanTarget('asset:2', 'tag-fingerprint-2'),
            ],
        );
        $metadata->expects(self::once())
            ->method('tagPlan')
            ->with($actor, [1, 2], [7, 9], true)
            ->willReturn($plan);
        $metadata->expects(self::never())->method('applyTags');
        $plans->expects(self::once())->method('issue')->with($plan)->willReturn('signed-plan');
        $controller = $this->metadataMutationController($authorization, $plans, $metadata);
        $request = Request::create('/', 'POST', [], [], [], [], json_encode([
            'assetIds' => [2, 1],
            'tagIds' => [9, 7],
            'replace' => true,
            'dryRun' => true,
        ], JSON_THROW_ON_ERROR));

        $response = $controller->bulkTag($request);
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(0, $data['tagged']);
        self::assertTrue($data['dryRun']);
        self::assertSame('signed-plan', $data['planToken']);
        self::assertSame(2, $data['eligible']);
    }

    #[Test]
    public function bulkTagDryRunRejectsANonexistentTagWithoutIssuingAToken(): void
    {
        $actor = ActorContext::user(7);
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn($actor);
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::never())->method('issue');
        $metadata = $this->createMock(AssetMetadataMutationService::class);
        $metadata->method('tagPlan')->willThrowException(new \InvalidArgumentException('Tag 99 does not exist.'));
        $metadata->expects(self::never())->method('applyTags');
        $controller = $this->metadataMutationController($authorization, $plans, $metadata);
        $request = Request::create('/', 'POST', [], [], [], [], json_encode([
            'assetIds' => [1],
            'tagIds' => [99],
            'dryRun' => true,
        ], JSON_THROW_ON_ERROR));

        $response = $controller->bulkTag($request);
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('Tag 99 does not exist.', $data['error']);
        self::assertArrayNotHasKey('planToken', $data);
    }

    #[Test]
    public function bulkPropertyAppliesTheSortedActorBoundPlanWithItsExactFingerprint(): void
    {
        $actor = ActorContext::user(7);
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn($actor);
        $plan = new ApplyPlan(
            'asset-bulk-property',
            $actor,
            ['assetIds' => [1, 2], 'data' => true, 'name' => 'reviewed', 'type' => 'bool'],
            ['version' => 1],
            [
                new ApplyPlanTarget('asset:1:property:reviewed', 'fp-1'),
                new ApplyPlanTarget('asset:2:property:reviewed', 'fp-2'),
            ],
        );
        $metadata = $this->createMock(AssetMetadataMutationService::class);
        $metadata->expects(self::once())->method('propertyPlan')
            ->with($actor, [1, 2], 'reviewed', 'bool', true)
            ->willReturn($plan);
        $metadata->expects(self::once())->method('applyProperty')->with(
            [1, 2],
            'reviewed',
            'bool',
            true,
            ['asset:1:property:reviewed' => 'fp-1', 'asset:2:property:reviewed' => 'fp-2'],
        )->willReturn(['updated' => 2, 'failed' => 0, 'errors' => [], 'observerWarnings' => []]);
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::once())->method('claim')->with('signed-plan', $plan)
            ->willReturn(ApplyPlanStatus::Claimed);
        $controller = $this->metadataMutationController($authorization, $plans, $metadata);

        $response = $controller->bulkProperty(Request::create('/', 'POST', content: json_encode([
            'assetIds' => [2, 1],
            'name' => 'reviewed',
            'type' => 'bool',
            'data' => true,
            'planToken' => 'signed-plan',
        ], JSON_THROW_ON_ERROR)));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(2, $body['updated']);
        self::assertSame(2, $body['eligible']);
        self::assertFalse($body['dryRun']);
        self::assertNull($body['planToken']);
        self::assertSame([], $body['errors']);
    }

    #[Test]
    public function zipDownloadPassesTheCurrentStudioActorToTheService(): void
    {
        $actor = ActorContext::user(7);
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn($actor);
        $zip = $this->createMock(AssetZipServiceInterface::class);
        $zip->expects(self::once())
            ->method('buildFromAssetIds')
            ->with([3], self::isInstanceOf(ZipBuildOptions::class), $actor)
            ->willReturn(new ZipBuildResult(null, 1, 0, 1));
        $controller = new AssetManagementController(
            $this->createMock(AssetSearchServiceInterface::class),
            $this->createMock(AssetPropertyService::class),
            new NullLogger(),
            new EventDispatcher(),
            $zip,
            $authorization,
            new ZipDownloadTokenStore(new ArrayAdapter(), new LockFactory(new InMemoryStore())),

            $this->createMock(ApplyPlanServiceInterface::class),
            $this->createMock(AssetMetadataMutationService::class),
        );
        $request = Request::create('/', 'POST', [], [], [], [], json_encode(['assetIds' => [3]], JSON_THROW_ON_ERROR));

        $response = $controller->downloadZip($request);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    #[Test]
    public function zipDownloadPublishesTheCompleteBuildResult(): void
    {
        $archive = (string) tempnam(sys_get_temp_dir(), 'apz_response_');
        file_put_contents($archive, 'archive');
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn(ActorContext::user(7));
        $zip = $this->createMock(AssetZipServiceInterface::class);
        $zip->method('buildFromAssetIds')->willReturn(new ZipBuildResult($archive, 4, 2, 1, true));
        $controller = new AssetManagementController(
            $this->createMock(AssetSearchServiceInterface::class),
            $this->createMock(AssetPropertyService::class),
            new NullLogger(),
            new EventDispatcher(),
            $zip,
            $authorization,
            new ZipDownloadTokenStore(new ArrayAdapter(), new LockFactory(new InMemoryStore())),

            $this->createMock(ApplyPlanServiceInterface::class),
            $this->createMock(AssetMetadataMutationService::class),
        );
        $request = Request::create('/', 'POST', [], [], [], [], json_encode(['assetIds' => [3, 4, 5, 6]], JSON_THROW_ON_ERROR));

        try {
            $response = $controller->downloadZip($request);

            self::assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $response);
            self::assertSame('4', $response->headers->get('X-Asset-Pilot-Requested'));
            self::assertSame('2', $response->headers->get('X-Asset-Pilot-Added'));
            self::assertSame('1', $response->headers->get('X-Asset-Pilot-Skipped'));
            self::assertSame('true', $response->headers->get('X-Asset-Pilot-Truncated'));
        } finally {
            @unlink($archive);
        }
    }

    #[Test]
    public function preparesAUserBoundNativeZipDownloadToken(): void
    {
        $tokens = new ZipDownloadTokenStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()));
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn(ActorContext::user(7));
        $controller = new AssetManagementController(
            $this->createMock(AssetSearchServiceInterface::class),
            $this->createMock(AssetPropertyService::class),
            new NullLogger(),
            new EventDispatcher(),
            $this->createMock(AssetZipServiceInterface::class),
            $authorization,
            $tokens,

            $this->createMock(ApplyPlanServiceInterface::class),
            $this->createMock(AssetMetadataMutationService::class),
        );
        $request = Request::create('/', 'POST', [], [], [], [], json_encode([
            'assetIds' => [3, 7],
            'strategy' => 'folder',
            'thumbnail' => 'web',
        ], JSON_THROW_ON_ERROR));

        $response = $controller->prepareZipDownload($request);
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $plan = $tokens->claim($data['token'], ActorContext::user(7));

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertNotNull($plan);
        self::assertSame([3, 7], $plan->assetIds);
        self::assertSame('folder', $plan->options->strategy);
        self::assertSame('web', $plan->options->thumbnail);
        self::assertNull($tokens->claim($data['token'], ActorContext::user(8)));
    }

    #[Test]
    public function zipOptionsRejectStructuredValues(): void
    {
        $controller = new AssetManagementController(
            $this->createMock(AssetSearchServiceInterface::class),
            $this->createMock(AssetPropertyService::class),
            new NullLogger(),
            new EventDispatcher(),
            $this->createMock(AssetZipServiceInterface::class),
            $this->createMock(ElementAuthorization::class),
            new ZipDownloadTokenStore(new ArrayAdapter(), new LockFactory(new InMemoryStore())),
            $this->createMock(ApplyPlanServiceInterface::class),
            $this->createMock(AssetMetadataMutationService::class),
        );
        $request = Request::create('/', 'POST', [], [], [], [], json_encode([
            'assetIds' => [3],
            'strategy' => ['folder'],
        ], JSON_THROW_ON_ERROR));

        $response = $controller->prepareZipDownload($request);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    private function metadataMutationController(
        ElementAuthorization $authorization,
        ApplyPlanServiceInterface $plans,
        AssetMetadataMutationService $metadata,
    ): AssetManagementController {
        return new class (
            $this->createMock(AssetSearchServiceInterface::class),
            $this->createMock(AssetPropertyService::class),
            new NullLogger(),
            new EventDispatcher(),
            $this->createMock(AssetZipServiceInterface::class),
            $authorization,
            new ZipDownloadTokenStore(new ArrayAdapter(), new LockFactory(new InMemoryStore())),
            $plans,
            $metadata,
        ) extends AssetManagementController {
            protected function firstForbiddenAsset(array $assetIds): ?int
            {
                return null;
            }
        };
    }

    private function controllerWithTagListing(Tag\Listing $listing): AssetManagementController
    {
        $controller = $this->getMockBuilder(AssetManagementController::class)
            ->setConstructorArgs([
                $this->createMock(AssetSearchServiceInterface::class),
                $this->createMock(AssetPropertyService::class),
                new NullLogger(),
                new EventDispatcher(),
                $this->createMock(AssetZipServiceInterface::class),
                $this->createMock(ElementAuthorization::class),
                new ZipDownloadTokenStore(new ArrayAdapter(), new LockFactory(new InMemoryStore())),
                $this->createMock(ApplyPlanServiceInterface::class),
                $this->createMock(AssetMetadataMutationService::class),
            ])
            ->onlyMethods(['createTagListing'])
            ->getMock();
        $controller->method('createTagListing')->willReturn($listing);

        return $controller;
    }
}

final class RecordingTagListing extends Tag\Listing
{
    /** @var array{string, array<string>}|null */
    public ?array $recordedCondition = null;

    /** @var array<string>|string|null */
    public array|string|null $recordedOrderKey = null;

    /** @var array<string>|string|null */
    public array|string|null $recordedOrder = null;

    public ?int $recordedOffset = null;

    public ?int $recordedLimit = null;

    /**
     * @param list<Tag> $tags
     */
    public function __construct(
        private readonly array $tags,
        private readonly int $total,
        private readonly ?\Throwable $failure = null,
    ) {}

    public function setCondition(string $condition, float|array|bool|int|string|null $conditionVariables = null): static
    {
        $this->recordedCondition = [$condition, is_array($conditionVariables) ? $conditionVariables : []];

        return $this;
    }

    public function setOrderKey(array|string $orderKey, bool $quote = true): static
    {
        $this->recordedOrderKey = $orderKey;

        return $this;
    }

    public function setOrder(array|string $order): static
    {
        $this->recordedOrder = $order;

        return $this;
    }

    public function setOffset(int $offset): static
    {
        $this->recordedOffset = $offset;

        return $this;
    }

    public function setLimit(?int $limit): static
    {
        $this->recordedLimit = $limit;

        return $this;
    }

    public function getTotalCount(): int
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->total;
    }

    /** @return list<Tag> */
    public function getTags(): array
    {
        return $this->tags;
    }
}
