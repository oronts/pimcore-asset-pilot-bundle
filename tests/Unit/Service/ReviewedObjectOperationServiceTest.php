<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Enum\BulkObjectStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\ReviewedSelectionError;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Exception\ReviewedSelectionException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\BulkObjectResult;
use Oronts\AssetPilotBundle\Model\BulkOrganizeReport;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\ApplyPlanService;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcher;
use Oronts\AssetPilotBundle\Service\OrganizePlanFingerprint;
use Oronts\AssetPilotBundle\Service\ReviewedObjectOperationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(ReviewedObjectOperationService::class)]
final class ReviewedObjectOperationServiceTest extends TestCase
{
    #[Test]
    public function previewIssuesSignedPlanWithoutCreatingOrExecutingARun(): void
    {
        $object = $this->object(42);
        $operation = $this->operation(42, 10);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::once())->method('dryRun')->with($object, TriggerType::Manual)->willReturn([$operation]);
        $organizer->expects(self::never())->method('organizeBulkDetailed');
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::never())->method('createRun');
        $dispatcher->expects(self::never())->method('dispatchBulk');
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::never())->method('start');
        $service = $this->service([$object], $organizer, $dispatcher, $runs);

        $result = $service->execute(
            'reorganize',
            [42],
            ['folder' => '/Staging', 'limit' => 25],
            TriggerType::Manual,
            true,
            false,
            actor: ActorContext::system(),
        );

        self::assertTrue($result->dryRun);
        self::assertNotNull($result->planToken);
        self::assertSame([$operation], $result->operations);
        self::assertNull($result->runId);
    }

    #[Test]
    public function changedObjectFingerprintRejectsApplyBeforeRunCreation(): void
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
        $dispatcher->expects(self::never())->method('createRun');
        $service = $this->service([$object], $organizer, $dispatcher);
        $preview = $service->execute(
            'reorganize',
            [42],
            ['folder' => '/Staging'],
            TriggerType::Manual,
            true,
            false,
            actor: ActorContext::system(),
        );
        $modifiedAt = 101;

        $this->expectReviewedError(ReviewedSelectionError::StalePlan);
        $service->execute(
            'reorganize',
            [42],
            ['folder' => '/Staging'],
            TriggerType::Manual,
            false,
            true,
            $preview->planToken,
            ActorContext::system(),
        );
    }

    #[Test]
    public function changedSelectorRejectsApplyBeforeRunCreation(): void
    {
        $object = $this->object(42);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn([$this->operation(42, 10)]);
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::never())->method('createRun');
        $service = $this->service([$object], $organizer, $dispatcher);
        $preview = $service->execute(
            'replay',
            [42],
            ['filters' => ['rule_name' => 'images'], 'limit' => 10],
            TriggerType::Manual,
            true,
            false,
            actor: ActorContext::system(),
        );

        $this->expectReviewedError(ReviewedSelectionError::StalePlan);
        $service->execute(
            'replay',
            [42],
            ['filters' => ['rule_name' => 'other'], 'limit' => 10],
            TriggerType::Manual,
            false,
            true,
            $preview->planToken,
            ActorContext::system(),
        );
    }

    #[Test]
    public function changedResolvedObjectSetRejectsApplyBeforeRunCreation(): void
    {
        $first = $this->object(42);
        $second = $this->object(43);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturnCallback(
            fn (AbstractObject $object): array => [$this->operation((int) $object->getId(), 10 + (int) $object->getId())],
        );
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::never())->method('createRun');
        $service = $this->service([$first, $second], $organizer, $dispatcher);
        $selector = ['folder' => '/Staging', 'limit' => 25];
        $preview = $service->execute(
            'reorganize',
            [42, 43],
            $selector,
            TriggerType::Manual,
            true,
            false,
            actor: ActorContext::system(),
        );

        $this->expectReviewedError(ReviewedSelectionError::StalePlan);
        $service->execute(
            'reorganize',
            [42],
            $selector,
            TriggerType::Manual,
            false,
            true,
            $preview->planToken,
            ActorContext::system(),
        );
    }

    #[Test]
    public function emptyEligibleSelectionRejectsApplyAsStale(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::never())->method('dryRun');
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::never())->method('createRun');
        $service = $this->service([], $organizer, $dispatcher);

        $this->expectReviewedError(ReviewedSelectionError::StalePlan);
        $service->execute(
            'organize',
            [42],
            ['mode' => 'object_id', 'objectId' => 42],
            TriggerType::Manual,
            false,
            true,
            'signed-plan',
            ActorContext::system(),
        );
    }

    #[Test]
    public function reusedTokenCannotDispatchASecondBatch(): void
    {
        $object = $this->object(42);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn([$this->operation(42, 10)]);
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::once())->method('createRun')->willReturn('run-1');
        $dispatcher->expects(self::once())->method('dispatchBulk')->willReturn('run-1');
        $service = $this->service([$object], $organizer, $dispatcher);
        $preview = $service->execute(
            'replay',
            [42],
            ['filters' => [], 'limit' => 10],
            TriggerType::Manual,
            true,
            false,
            actor: ActorContext::system(),
        );
        $applyArguments = [
            'replay',
            [42],
            ['filters' => [], 'limit' => 10],
            TriggerType::Manual,
            false,
            true,
            $preview->planToken,
            ActorContext::system(),
        ];
        $service->execute(...$applyArguments);

        $this->expectReviewedError(ReviewedSelectionError::StalePlan);
        $service->execute(...$applyArguments);
    }

    #[Test]
    public function asyncApplyCarriesExactKindRequestFingerprintsAndSystemActor(): void
    {
        $object = $this->object(42);
        $operation = $this->operation(42, 10);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn([$operation]);
        $fingerprint = (new OrganizePlanFingerprint())->forOperations($object, [$operation]);
        $request = [
            'objectIds' => [42],
            'operations' => [[
                'assetId' => 10,
                'error' => null,
                'objectClass' => 'Product',
                'objectId' => 42,
                'ruleName' => 'product-assets',
                'sourcePath' => '/incoming/10.jpg',
                'status' => 'pending',
                'targetPath' => '/organized/10.jpg',
            ]],
            'selector' => ['filters' => ['rule_name' => 'images'], 'limit' => 10],
            'trigger' => TriggerType::Manual->value,
        ];
        $actor = ActorContext::system();
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::once())->method('createRun')->with(
            [42],
            TriggerType::Manual,
            self::callback(static fn (ActorContext $actual): bool => $actual->type === ActorType::System),
            [42 => $fingerprint],
            'replay',
            $request,
        )->willReturn('replay-run');
        $dispatcher->expects(self::once())->method('dispatchBulk')->with(
            [42],
            TriggerType::Manual,
            $actor,
            'replay-run',
            [42 => $fingerprint],
        )->willReturn('replay-run');
        $service = $this->service([$object], $organizer, $dispatcher);
        $selector = ['filters' => ['rule_name' => 'images'], 'limit' => 10];
        $preview = $service->execute('replay', [42], $selector, TriggerType::Manual, true, false, actor: $actor);

        $result = $service->execute(
            'replay',
            [42],
            $selector,
            TriggerType::Manual,
            false,
            true,
            $preview->planToken,
            $actor,
        );

        self::assertSame('replay-run', $result->runId);
        self::assertSame(OperationRunStatus::Queued, $result->runStatus);
        self::assertSame(1, $result->dispatched);
    }

    #[Test]
    public function synchronousApplyCreatesStartsCompletesAndFinishesRun(): void
    {
        $object = $this->object(42);
        $operation = $this->operation(42, 10);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn([$operation]);
        $fingerprint = (new OrganizePlanFingerprint())->forOperations($object, [$operation]);
        $report = new BulkOrganizeReport([], [new BulkObjectResult(42, BulkObjectStatus::Succeeded, operationCount: 1)]);
        $organizer->expects(self::once())->method('organizeBulkDetailed')->with(
            [42],
            TriggerType::Manual,
            null,
            null,
            null,
            self::isCallable(),
            self::isCallable(),
            [42 => $fingerprint],
        )->willReturnCallback(static function (array $ids, TriggerType $trigger, mixed $progress, mixed $dispatchedAt, mixed $stale, callable $cancel, callable $before) use ($report): BulkOrganizeReport {
            self::assertFalse($cancel());
            self::assertTrue($before(42));

            return $report;
        });
        $dispatcher = $this->createMock(OrganizeDispatcher::class);
        $dispatcher->expects(self::once())->method('createRun')->willReturn('sync-run');
        $dispatcher->expects(self::never())->method('dispatchBulk');
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())->method('start')->with('sync-run')->willReturn(true);
        $runs->method('isCancellationRequested')->willReturn(false);
        $runs->expects(self::once())->method('startItem')->with('sync-run', 'object:42')->willReturn(true);
        $runs->expects(self::once())->method('completeItem')->with(
            'sync-run',
            'object:42',
            OperationRunItemStatus::Completed,
            ['operationCount' => 1],
            null,
        )->willReturn(true);
        $runs->expects(self::once())->method('finish')->with('sync-run')->willReturn(OperationRunStatus::Completed);
        $service = $this->service([$object], $organizer, $dispatcher, $runs);
        $selector = ['assetIds' => [10], 'mode' => 'asset_ids'];
        $preview = $service->execute('reorganize', [42], $selector, TriggerType::Manual, true, false, actor: ActorContext::system());

        $result = $service->execute(
            'reorganize',
            [42],
            $selector,
            TriggerType::Manual,
            false,
            false,
            $preview->planToken,
            ActorContext::system(),
        );

        self::assertSame('sync-run', $result->runId);
        self::assertSame(OperationRunStatus::Completed, $result->runStatus);
        self::assertSame(1, $result->organized);
        self::assertSame([$report->objectResults[0]], $result->objectResults);
    }

    #[Test]
    public function missingAndMalformedTokensAreDistinguished(): void
    {
        $object = $this->object(42);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn([$this->operation(42, 10)]);
        $service = $this->service([$object], $organizer);

        try {
            $service->execute('replay', [42], [], TriggerType::Manual, false, true);
            self::fail('Missing token was accepted.');
        } catch (ReviewedSelectionException $e) {
            self::assertSame(ReviewedSelectionError::MissingPlanToken, $e->error);
        }

        $this->expectReviewedError(ReviewedSelectionError::MalformedPlanToken);
        $service->execute('replay', [42], [], TriggerType::Manual, false, true, 'broken', ActorContext::system());
    }

    /**
     * @param list<AbstractObject> $objects
     *
     * @return ReviewedObjectOperationService&MockObject
     */
    private function service(
        array $objects,
        ?AssetOrganizer $organizer = null,
        ?OrganizeDispatcher $dispatcher = null,
        ?OperationRunStoreInterface $runs = null,
    ): ReviewedObjectOperationService {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn(ActorContext::system());
        $authorization->method('isAllowed')->willReturn(true);
        $objectMap = [];
        foreach ($objects as $object) {
            $objectMap[(int) $object->getId()] = $object;
        }
        $service = $this->getMockBuilder(ReviewedObjectOperationService::class)
            ->setConstructorArgs([
                $organizer ?? $this->createMock(AssetOrganizer::class),
                $dispatcher ?? $this->createMock(OrganizeDispatcher::class),
                $authorization,
                $runs ?? $this->createMock(OperationRunStoreInterface::class),
                new ApplyPlanService('test-secret', new ArrayAdapter(), new LockFactory(new InMemoryStore())),
                new OrganizePlanFingerprint(),
                new NullLogger(),
                ['rules' => ['product-assets']],
            ])
            ->onlyMethods(['loadObject'])
            ->getMock();
        $service->method('loadObject')->willReturnCallback(
            static fn (int $id): ?AbstractObject => $objectMap[$id] ?? null,
        );

        return $service;
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
            $assetId,
            '/incoming/' . $assetId . '.jpg',
            '/organized/' . $assetId . '.jpg',
            $objectId,
            'Product',
            'product-assets',
            OperationStatus::Pending,
            TriggerType::Manual,
        );
    }

    private function expectReviewedError(ReviewedSelectionError $error): void
    {
        $this->expectException(ReviewedSelectionException::class);
        $this->expectExceptionObject(new ReviewedSelectionException($error, $this->errorMessage($error)));
    }

    private function errorMessage(ReviewedSelectionError $error): string
    {
        return match ($error) {
            ReviewedSelectionError::StalePlan => 'The preview plan is stale or already applied. Run a new preview.',
            ReviewedSelectionError::MalformedPlanToken => 'The plan token is malformed or has an invalid signature.',
            default => throw new \LogicException('Unsupported test error.'),
        };
    }
}
