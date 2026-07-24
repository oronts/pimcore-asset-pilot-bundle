<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Audit\AuditWriterInterface;
use Oronts\AssetPilotBundle\Engine\RuleEngine;
use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Enum\BulkObjectStatus;
use Oronts\AssetPilotBundle\Enum\DriftEligibility;
use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Event\BulkOrganizeEvent;
use Oronts\AssetPilotBundle\Exception\LostRunItemOwnershipException;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\AssetFieldInfo;
use Oronts\AssetPilotBundle\Model\BulkObjectResult;
use Oronts\AssetPilotBundle\Model\DriftAssessment;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Model\MovePlan;
use Oronts\AssetPilotBundle\Model\OperationHandle;
use Oronts\AssetPilotBundle\Model\OperationIntent;
use Oronts\AssetPilotBundle\Model\OperationResult;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Model\RuleMatch;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractor;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractorInterface;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\LoopGuardedAssetSaver;
use Oronts\AssetPilotBundle\Service\MovePlanner;
use Oronts\AssetPilotBundle\Service\OperationJournalInterface;
use Oronts\AssetPilotBundle\Service\OrganizePlanFingerprint;
use Oronts\AssetPilotBundle\Service\RuleExecutionFingerprint;
use Oronts\AssetPilotBundle\Strategy\FirstAssignmentStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\Exception\LockStorageException;

#[CoversClass(AssetOrganizer::class)]
class AssetOrganizerTest extends TestCase
{
    private function organizer(EventDispatcher $dispatcher, ?ElementAuthorization $authorization = null): AssetOrganizer
    {
        return new AssetOrganizer(
            $this->createMock(RuleEngine::class),
            $this->createMock(AssetFieldExtractor::class),
            $this->createMock(MovePlanner::class),
            $this->createMock(AuditWriterInterface::class),
            $this->journal(),
            $dispatcher,
            $this->createMock(LoopGuard::class),
            new NullLogger(),
            $authorization ?? $this->authorization(),
            new OrganizePlanFingerprint(),
            new LoopGuardedAssetSaver($this->createMock(LoopGuard::class)),
        );
    }

    #[Test]
    public function organizeBulkFiresBulkStartedAndCompletedEvents(): void
    {
        $dispatcher = new EventDispatcher();
        $seen = [];
        $dispatcher->addListener(
            AssetPilotEvents::BULK_STARTED,
            static function (BulkOrganizeEvent $e) use (&$seen): void {
                $seen[] = ['started', $e->objectIds, $e->triggerType];
            },
        );
        $dispatcher->addListener(
            AssetPilotEvents::BULK_COMPLETED,
            static function (BulkOrganizeEvent $e) use (&$seen): void {
                $seen[] = ['completed', $e->objectIds, $e->results];
            },
        );

        $this->organizer($dispatcher)->organizeBulk([], TriggerType::Api);

        self::assertSame('started', $seen[0][0]);
        self::assertSame(TriggerType::Api, $seen[0][2]);
        self::assertSame('completed', $seen[1][0]);
        self::assertSame([], $seen[1][2]);
    }

    #[Test]
    public function bulkObserverFailuresDoNotStopLaterObserversOrChangeTheDomainReport(): void
    {
        $dispatcher = new EventDispatcher();
        $seen = [];
        foreach ([AssetPilotEvents::BULK_STARTED, AssetPilotEvents::BULK_COMPLETED] as $eventName) {
            $dispatcher->addListener($eventName, static fn (): never => throw new \RuntimeException('Observer failed.'), 10);
            $dispatcher->addListener($eventName, static function () use (&$seen, $eventName): void {
                $seen[] = $eventName;
            });
        }

        $report = $this->organizer($dispatcher)->organizeBulkDetailed([], TriggerType::Api);

        self::assertSame([AssetPilotEvents::BULK_STARTED, AssetPilotEvents::BULK_COMPLETED], $seen);
        self::assertSame(0, $report->attemptedCount());
        self::assertSame(0, $report->failedCount());
        self::assertSame([
            'Bulk-start observer delivery failed.',
            'Bulk-completed observer delivery failed.',
        ], $report->observerWarnings);
    }

    #[Test]
    public function organizeBulkReportsMissingStaleAndExceptionalObjectsAndAlwaysAdvancesProgress(): void
    {
        $stale = $this->createMock(Concrete::class);
        $stale->method('getId')->willReturn(2);
        $stale->method('getModificationDate')->willReturn(101);
        $exceptional = $this->createMock(AbstractObject::class);
        $exceptional->method('getId')->willReturn(3);
        $authorization = $this->authorization();
        $organizer = new class (
            $this->createMock(RuleEngine::class),
            $this->createMock(AssetFieldExtractor::class),
            $this->createMock(MovePlanner::class),
            $this->createMock(AuditWriterInterface::class),
            $this->journal(),
            new EventDispatcher(),
            $this->createMock(LoopGuard::class),
            new NullLogger(),
            $authorization,
            [2 => $stale, 3 => $exceptional],
        ) extends AssetOrganizer {
            /** @param array<int, AbstractObject> $objects */
            public function __construct(
                RuleEngine $engine,
                AssetFieldExtractor $extractor,
                MovePlanner $planner,
                AuditWriterInterface $audit,
                OperationJournalInterface $journal,
                EventDispatcher $dispatcher,
                LoopGuard $loopGuard,
                NullLogger $logger,
                ElementAuthorization $authorization,
                private readonly array $objects,
            ) {
                parent::__construct($engine, $extractor, $planner, $audit, $journal, $dispatcher, $loopGuard, $logger, $authorization, new OrganizePlanFingerprint(), new LoopGuardedAssetSaver($loopGuard));
            }

            protected function loadObject(int $objectId): ?AbstractObject
            {
                return $this->objects[$objectId] ?? null;
            }

            public function organize(AbstractObject $object, TriggerType $triggerType = TriggerType::ObjectSave, ?string $ruleName = null, ?string $expectedFingerprint = null): array
            {
                throw new \RuntimeException('Infrastructure failed.');
            }
        };
        $progress = [];
        $requeued = [];
        $completed = [];

        $report = $organizer->organizeBulkDetailed(
            [1, 2, 3],
            TriggerType::BulkOperation,
            static function (int $current) use (&$progress): void {
                $progress[] = $current;
            },
            100,
            static function (int $objectId) use (&$requeued): void {
                $requeued[] = $objectId;
            },
            afterObject: static function (BulkObjectResult $result) use (&$completed): void {
                $completed[] = $result->objectId;
            },
        );

        self::assertSame([1, 2, 3], $progress);
        self::assertSame([1, 2, 3], $completed);
        self::assertSame([2], $requeued);
        self::assertSame(3, $report->attemptedCount());
        self::assertSame(2, $report->failedCount());
        self::assertSame(1, $report->skippedCount());
        self::assertSame([
            BulkObjectStatus::Failed,
            BulkObjectStatus::Skipped,
            BulkObjectStatus::Failed,
        ], array_column($report->objectResults, 'status'));

        $resumed = $organizer->organizeBulkDetailed(
            [1, 2, 3],
            TriggerType::BulkOperation,
            beforeObject: static fn (int $objectId): bool => $objectId !== 1,
        );
        self::assertSame([2, 3], array_column($resumed->objectResults, 'objectId'));
    }

    #[Test]
    public function organizeBulkDetailedAbortsWhenAnItemCompletionLosesOwnership(): void
    {
        $object = $this->createMock(AbstractObject::class);
        $object->method('getId')->willReturn(5);
        $authorization = $this->authorization();
        $organizer = new class (
            $this->createMock(RuleEngine::class),
            $this->createMock(AssetFieldExtractor::class),
            $this->createMock(MovePlanner::class),
            $this->createMock(AuditWriterInterface::class),
            $this->journal(),
            new EventDispatcher(),
            $this->createMock(LoopGuard::class),
            new NullLogger(),
            $authorization,
            [5 => $object],
        ) extends AssetOrganizer {
            /** @param array<int, AbstractObject> $objects */
            public function __construct(
                RuleEngine $engine,
                AssetFieldExtractor $extractor,
                MovePlanner $planner,
                AuditWriterInterface $audit,
                OperationJournalInterface $journal,
                EventDispatcher $dispatcher,
                LoopGuard $loopGuard,
                NullLogger $logger,
                ElementAuthorization $authorization,
                private readonly array $objects,
            ) {
                parent::__construct($engine, $extractor, $planner, $audit, $journal, $dispatcher, $loopGuard, $logger, $authorization, new OrganizePlanFingerprint(), new LoopGuardedAssetSaver($loopGuard));
            }

            protected function loadObject(int $objectId): ?AbstractObject
            {
                return $this->objects[$objectId] ?? null;
            }

            public function organize(AbstractObject $object, TriggerType $triggerType = TriggerType::ObjectSave, ?string $ruleName = null, ?string $expectedFingerprint = null): array
            {
                return [];
            }
        };

        $this->expectException(LostRunItemOwnershipException::class);

        $organizer->organizeBulkDetailed(
            [5],
            TriggerType::BulkOperation,
            afterObject: static fn (BulkObjectResult $result): bool => false,
        );
    }

    #[Test]
    public function organizeBulkRethrowsRetryableInfrastructureFailures(): void
    {
        $object = $this->createMock(AbstractObject::class);
        $object->method('getId')->willReturn(3);
        $organizer = new class (
            $this->createMock(RuleEngine::class),
            $this->createMock(AssetFieldExtractor::class),
            $this->createMock(MovePlanner::class),
            $this->createMock(AuditWriterInterface::class),
            $this->journal(),
            new EventDispatcher(),
            $this->createMock(LoopGuard::class),
            new NullLogger(),
            $this->authorization(),
            $object,
        ) extends AssetOrganizer {
            public function __construct(
                RuleEngine $engine,
                AssetFieldExtractor $extractor,
                MovePlanner $planner,
                AuditWriterInterface $audit,
                OperationJournalInterface $journal,
                EventDispatcher $dispatcher,
                LoopGuard $loopGuard,
                NullLogger $logger,
                ElementAuthorization $authorization,
                private readonly AbstractObject $object,
            ) {
                parent::__construct($engine, $extractor, $planner, $audit, $journal, $dispatcher, $loopGuard, $logger, $authorization, new OrganizePlanFingerprint(), new LoopGuardedAssetSaver($loopGuard));
            }

            protected function loadObject(int $objectId): ?AbstractObject
            {
                return $this->object;
            }

            public function organize(AbstractObject $object, TriggerType $triggerType = TriggerType::ObjectSave, ?string $ruleName = null, ?string $expectedFingerprint = null): array
            {
                throw new LockStorageException('Redis unavailable.');
            }
        };

        $this->expectException(LockStorageException::class);

        $organizer->organizeBulkDetailed([3], TriggerType::BulkOperation);
    }

    #[Test]
    public function organizeFailsClosedWhenTheActorCannotPublishTheObject(): void
    {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->expects(self::once())->method('isAllowed')->with(self::isInstanceOf(AbstractObject::class), 'publish')->willReturn(false);
        $object = $this->createMock(AbstractObject::class);
        $object->method('getId')->willReturn(42);

        self::assertSame([], $this->organizer(new EventDispatcher(), $authorization)->organize($object));
    }

    #[Test]
    public function plannedOrganizeRevalidatesAForceReloadedObjectUnderTheObjectLock(): void
    {
        $initial = $this->createMock(Concrete::class);
        $initial->method('getId')->willReturn(42);
        $initial->method('getClassName')->willReturn('Product');
        $initial->method('getRealFullPath')->willReturn('/products/initial');
        $initial->method('getModificationDate')->willReturn(100);
        $initial->method('getType')->willReturn('object');
        $initial->method('getVersionCount')->willReturn(1);
        $live = $this->createMock(Concrete::class);
        $live->method('getId')->willReturn(42);
        $live->method('getClassName')->willReturn('Product');
        $live->method('getRealFullPath')->willReturn('/products/changed');
        $live->method('getModificationDate')->willReturn(101);
        $live->method('getType')->willReturn('object');
        $live->method('getVersionCount')->willReturn(2);
        $extractor = $this->createMock(AssetFieldExtractorInterface::class);
        $extractor->expects(self::once())->method('extract')->with($live)->willReturn([]);
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->expects(self::once())->method('acquireObject')->with(42)->willReturn(true);
        $loopGuard->expects(self::once())->method('releaseObject')->with(42);
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);
        $fingerprints = new OrganizePlanFingerprint();
        $expected = $fingerprints->forOperations($initial, []);
        $organizer = new class (
            $this->createMock(RuleEngineInterface::class),
            $extractor,
            $this->createMock(MovePlanner::class),
            $this->createMock(AuditWriterInterface::class),
            $this->journal(),
            new EventDispatcher(),
            $loopGuard,
            new NullLogger(),
            $authorization,
            $live,
            $fingerprints,
        ) extends AssetOrganizer {
            public function __construct(
                RuleEngineInterface $engine,
                AssetFieldExtractorInterface $extractor,
                MovePlanner $planner,
                AuditWriterInterface $audit,
                OperationJournalInterface $journal,
                EventDispatcher $dispatcher,
                LoopGuard $loopGuard,
                NullLogger $logger,
                ElementAuthorization $authorization,
                private readonly AbstractObject $live,
                OrganizePlanFingerprint $fingerprints,
            ) {
                parent::__construct($engine, $extractor, $planner, $audit, $journal, $dispatcher, $loopGuard, $logger, $authorization, $fingerprints, new LoopGuardedAssetSaver($loopGuard));
            }

            protected function reloadObject(int $objectId): ?AbstractObject
            {
                return $this->live;
            }
        };

        $this->expectException(StaleApplyPlanException::class);

        $organizer->organize($initial, TriggerType::Api, expectedFingerprint: $expected);
    }

    #[Test]
    public function reviewedApplyRevalidatesUnderAssetLocksAndSkipsAStaleAssetDerivedPlan(): void
    {
        $object = $this->reviewedObject(42);
        $fingerprints = new OrganizePlanFingerprint();
        $planned = $this->reviewedPendingOperation(7, '/organized/a/7.jpg');
        $drifted = $this->reviewedPendingOperation(7, '/organized/b/7.jpg');
        $expected = $fingerprints->forOperations($object, [$planned]);
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireObject')->willReturn(true);
        $loopGuard->method('acquireAsset')->willReturn(true);
        // The object fingerprint still matches on the first pass, but the asset's canonical target drifts
        // before the asset lock; the re-validation under the lock must catch it and skip.
        $organizer = $this->reviewedOrganizer($object, $loopGuard, $fingerprints, [[$planned], [$drifted]]);

        $this->expectException(StaleApplyPlanException::class);

        $organizer->organize($object, TriggerType::Api, expectedFingerprint: $expected);
    }

    #[Test]
    public function reviewedApplyAcquiresEveryPlanAssetLockInStableIdOrderBeforeMutating(): void
    {
        $object = $this->reviewedObject(42);
        $fingerprints = new OrganizePlanFingerprint();
        $plan = [$this->reviewedPendingOperation(9, '/o/9.jpg'), $this->reviewedPendingOperation(3, '/o/3.jpg'), $this->reviewedPendingOperation(7, '/o/7.jpg')];
        $expected = $fingerprints->forOperations($object, $plan);
        $acquired = [];
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireObject')->willReturn(true);
        $loopGuard->method('acquireAsset')->willReturnCallback(function (int $assetId) use (&$acquired): bool {
            $acquired[] = $assetId;

            return true;
        });
        // A stable plan: the extractor yields no fields so nothing is mutated after locking, isolating the
        // lock-acquisition order.
        $organizer = $this->reviewedOrganizer($object, $loopGuard, $fingerprints, [$plan, $plan]);

        $organizer->organize($object, TriggerType::Api, expectedFingerprint: $expected);

        self::assertSame([3, 7, 9], $acquired, 'every plan asset is locked once, in ascending id order');
    }

    #[Test]
    public function reviewedApplySkipsAnAssetWhoseLiveTargetDivergedFromTheReviewedTarget(): void
    {
        $object = $this->reviewedObject(7);
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(20);
        $asset->method('getRealFullPath')->willReturn('/source/f.jpg');
        $asset->method('isAllowed')->willReturn(true);
        $rule = Rule::fromConfig('r', ['class' => 'Product', 'fields' => ['images'], 'target_path' => '/{{ date }}']);
        $engine = $this->createMock(RuleEngineInterface::class);
        $engine->method('matchField')->willReturn([new RuleMatch($rule, $object, $asset, '/reviewed')]);
        $extractor = $this->createMock(AssetFieldExtractorInterface::class);
        $extractor->method('extract')->willReturn([new AssetFieldInfo('images', null, 'image', [$asset])]);
        // The target template renders one path at review/validate time and a later one at execute time
        // (dryRun=false), modelling a non-deterministic {{ date }} crossing a bucket under the lock.
        $planner = $this->createMock(MovePlanner::class);
        $planner->method('plan')->willReturnCallback(
            static fn (Asset $a, AbstractObject $o, Rule $ru, string $path, TriggerType $t, bool $dryRun): MovePlan => $dryRun
                ? MovePlan::proceed('/reviewed', 'f.jpg', '/reviewed/f.jpg')
                : MovePlan::proceed('/diverged', 'f.jpg', '/diverged/f.jpg'),
        );
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireObject')->willReturn(true);
        $loopGuard->method('acquireAsset')->willReturn(true);
        $loopGuard->expects(self::never())->method('acquireTarget');
        $organizer = new class (
            $engine,
            $extractor,
            $planner,
            $this->createMock(AuditWriterInterface::class),
            $this->journal(),
            new EventDispatcher(),
            $loopGuard,
            new NullLogger(),
            $this->authorization(),
            new OrganizePlanFingerprint(),
            new LoopGuardedAssetSaver($loopGuard),
            $object,
        ) extends AssetOrganizer {
            public function __construct(
                RuleEngineInterface $engine,
                AssetFieldExtractorInterface $extractor,
                MovePlanner $planner,
                AuditWriterInterface $audit,
                OperationJournalInterface $journal,
                EventDispatcher $dispatcher,
                LoopGuard $loopGuard,
                NullLogger $logger,
                ElementAuthorization $authorization,
                OrganizePlanFingerprint $fingerprints,
                LoopGuardedAssetSaver $saver,
                private readonly AbstractObject $live,
            ) {
                parent::__construct($engine, $extractor, $planner, $audit, $journal, $dispatcher, $loopGuard, $logger, $authorization, $fingerprints, $saver);
            }

            protected function reloadObject(int $objectId): ?AbstractObject
            {
                return $this->live;
            }
        };
        $expected = (new OrganizePlanFingerprint())->forOperations($object, $organizer->dryRun($object, TriggerType::Manual));

        $results = $organizer->organize($object, TriggerType::Manual, expectedFingerprint: $expected);

        self::assertCount(1, $results);
        self::assertSame(OperationStatus::Skipped, $results[0]->status, 'the divergent target must not be executed');
        self::assertSame('Asset target changed after review', $results[0]->message);
    }

    #[Test]
    public function reviewedApplySkipsWhenTheLiveRuleBehaviourDivergesEvenAtTheSameTarget(): void
    {
        $object = $this->reviewedObject(7);
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(20);
        $asset->method('getRealFullPath')->willReturn('/source/f.jpg');
        $asset->method('isAllowed')->willReturn(true);
        // Two rules with the same name AND the same resolved target, differing only in execution behaviour
        // (strategy). The reviewed plan committed to rule A; a non-deterministic provider returns rule B at
        // execution. The target tripwire cannot tell them apart, so the execution fingerprint must.
        $ruleA = Rule::fromConfig('r', ['class' => 'Product', 'fields' => ['images'], 'target_path' => '/same', 'strategy' => 'first_assignment']);
        $ruleB = Rule::fromConfig('r', ['class' => 'Product', 'fields' => ['images'], 'target_path' => '/same', 'strategy' => 'always']);
        $reviewedOp = new MoveOperation(20, '/source/f.jpg', '/same/f.jpg', 7, 'Product', 'r', OperationStatus::Pending, TriggerType::Manual, executionFingerprint: (new RuleExecutionFingerprint())->forRule($ruleA));
        $liveMatch = new RuleMatch($ruleB, $object, $asset, '/same');
        $planner = $this->createMock(MovePlanner::class);
        $planner->method('plan')->willReturn(MovePlan::proceed('/same', 'f.jpg', '/same/f.jpg'));
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireObject')->willReturn(true);
        $loopGuard->method('acquireAsset')->willReturn(true);
        $loopGuard->expects(self::never())->method('acquireTarget');
        $organizer = new class (
            $this->createMock(RuleEngineInterface::class),
            $this->createMock(AssetFieldExtractorInterface::class),
            $planner,
            $this->createMock(AuditWriterInterface::class),
            $this->journal(),
            new EventDispatcher(),
            $loopGuard,
            new NullLogger(),
            $this->authorization(),
            new OrganizePlanFingerprint(),
            new LoopGuardedAssetSaver($loopGuard),
            $object,
            $reviewedOp,
            $liveMatch,
        ) extends AssetOrganizer {
            public function __construct(
                RuleEngineInterface $engine,
                AssetFieldExtractorInterface $extractor,
                MovePlanner $planner,
                AuditWriterInterface $audit,
                OperationJournalInterface $journal,
                EventDispatcher $dispatcher,
                LoopGuard $loopGuard,
                NullLogger $logger,
                ElementAuthorization $authorization,
                OrganizePlanFingerprint $fingerprints,
                LoopGuardedAssetSaver $saver,
                private readonly AbstractObject $live,
                private readonly MoveOperation $reviewedOperation,
                private readonly RuleMatch $liveMatch,
            ) {
                parent::__construct($engine, $extractor, $planner, $audit, $journal, $dispatcher, $loopGuard, $logger, $authorization, $fingerprints, $saver);
            }

            protected function reloadObject(int $objectId): ?AbstractObject
            {
                return $this->live;
            }

            public function dryRun(AbstractObject $object, TriggerType $triggerType = TriggerType::Manual, ?string $ruleName = null): array
            {
                return [$this->reviewedOperation];
            }

            protected function bestMatches(AbstractObject $object, iterable $fieldInfos, ?string $ruleName): array
            {
                return [['asset' => $this->liveMatch->asset, 'match' => $this->liveMatch, 'field' => 'images', 'locale' => null]];
            }
        };
        $expected = (new OrganizePlanFingerprint())->forOperations($object, [$reviewedOp]);

        $results = $organizer->organize($object, TriggerType::Manual, expectedFingerprint: $expected);

        self::assertCount(1, $results);
        self::assertSame(OperationStatus::Skipped, $results[0]->status, 'a same-target rule whose behaviour changed after review must not execute');
        self::assertSame('Asset operation changed after review', $results[0]->message);
    }

    #[Test]
    public function organizeReplaysTheForceReloadedStateWhenTheObjectWasSavedDuringProcessing(): void
    {
        $initial = $this->createMock(Concrete::class);
        $initial->method('getId')->willReturn(42);
        $initial->method('getClassName')->willReturn('Product');
        $latest = $this->createMock(Concrete::class);
        $latest->method('getId')->willReturn(42);
        $latest->method('getClassName')->willReturn('Product');
        $extractor = $this->createMock(AssetFieldExtractorInterface::class);
        $extractor->expects(self::exactly(2))->method('extract')->with(self::logicalOr($initial, $latest))->willReturn([]);
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->expects(self::exactly(2))->method('acquireObject')->with(42)->willReturn(true);
        $loopGuard->expects(self::exactly(2))->method('consumeObjectDirty')->with(42)->willReturnOnConsecutiveCalls(true, false);
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);
        $organizer = new class (
            $this->createMock(RuleEngineInterface::class),
            $extractor,
            $this->createMock(MovePlanner::class),
            $this->createMock(AuditWriterInterface::class),
            $this->journal(),
            new EventDispatcher(),
            $loopGuard,
            new NullLogger(),
            $authorization,
            $latest,
        ) extends AssetOrganizer {
            public function __construct(
                RuleEngineInterface $engine,
                AssetFieldExtractorInterface $extractor,
                MovePlanner $planner,
                AuditWriterInterface $audit,
                OperationJournalInterface $journal,
                EventDispatcher $dispatcher,
                LoopGuard $loopGuard,
                NullLogger $logger,
                ElementAuthorization $authorization,
                private readonly AbstractObject $latest,
            ) {
                parent::__construct($engine, $extractor, $planner, $audit, $journal, $dispatcher, $loopGuard, $logger, $authorization, new OrganizePlanFingerprint(), new LoopGuardedAssetSaver($loopGuard));
            }

            protected function reloadObject(int $objectId): ?AbstractObject
            {
                return $this->latest;
            }
        };

        self::assertSame([], $organizer->organize($initial));
    }

    #[Test]
    public function organizeFailsLoudWhenTheObjectNeverStopsChanging(): void
    {
        $object = $this->createMock(Concrete::class);
        $object->method('getId')->willReturn(42);
        $object->method('getClassName')->willReturn('Product');
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireObject')->willReturn(true);
        $loopGuard->method('consumeObjectDirty')->willReturn(true);
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);
        $organizer = new AssetOrganizer(
            $this->createMock(RuleEngineInterface::class),
            $this->createMock(AssetFieldExtractorInterface::class),
            $this->createMock(MovePlanner::class),
            $this->createMock(AuditWriterInterface::class),
            $this->journal(),
            new EventDispatcher(),
            $loopGuard,
            new NullLogger(),
            $authorization,
            new OrganizePlanFingerprint(),
            new LoopGuardedAssetSaver($loopGuard),
            maxObjectReplays: 1,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('kept changing');

        $organizer->organize($object);
    }

    #[Test]
    public function organizeWithHeartbeatRefreshesTheExecutionLeaseBeforeThePass(): void
    {
        $object = $this->createMock(Concrete::class);
        $object->method('getId')->willReturn(42);
        $object->method('getClassName')->willReturn('Product');
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireObject')->willReturn(true);
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);
        $heartbeats = 0;
        $organizer = new AssetOrganizer(
            $this->createMock(RuleEngineInterface::class),
            $this->createMock(AssetFieldExtractorInterface::class),
            $this->createMock(MovePlanner::class),
            $this->createMock(AuditWriterInterface::class),
            $this->journal(),
            new EventDispatcher(),
            $loopGuard,
            new NullLogger(),
            $authorization,
            new OrganizePlanFingerprint(),
            new LoopGuardedAssetSaver($loopGuard),
        );

        $results = $organizer->organizeWithHeartbeat(
            $object,
            TriggerType::BulkOperation,
            static function () use (&$heartbeats): void {
                ++$heartbeats;
            },
        );

        self::assertSame([], $results);
        self::assertSame(1, $heartbeats);
    }

    #[Test]
    public function dryRunFailsClosedWhenTheActorCannotViewTheObject(): void
    {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->expects(self::once())->method('isAllowed')->with(self::isInstanceOf(AbstractObject::class), 'view')->willReturn(false);

        self::assertSame([], $this->organizer(new EventDispatcher(), $authorization)->dryRun($this->createMock(AbstractObject::class)));
    }

    #[Test]
    public function dryRunDoesNotEvaluateAssetsOutsideTheActorWorkspace(): void
    {
        $object = $this->createMock(Concrete::class);
        $object->method('getId')->willReturn(7);
        $object->method('getClassName')->willReturn('Product');
        $asset = $this->asset('/restricted/f.jpg');
        $extractor = $this->createMock(AssetFieldExtractorInterface::class);
        $extractor->method('extract')->willReturn([new AssetFieldInfo('image', null, 'image', [$asset])]);
        $engine = $this->createMock(RuleEngineInterface::class);
        $engine->expects(self::never())->method('matchField');
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn ($element, string $permission): bool => $element === $object && $permission === 'view',
        );
        $organizer = new AssetOrganizer(
            $engine,
            $extractor,
            $this->createMock(MovePlanner::class),
            $this->createMock(AuditWriterInterface::class),
            $this->journal(),
            new EventDispatcher(),
            $this->createMock(LoopGuard::class),
            new NullLogger(),
            $authorization,
            new OrganizePlanFingerprint(),
            new LoopGuardedAssetSaver($this->createMock(LoopGuard::class)),
        );

        self::assertSame([], $organizer->dryRun($object));
    }

    #[Test]
    public function preflightApplyRejectsAnUnauthorizedSourceBeforeMutation(): void
    {
        $asset = $this->asset('/restricted/f.jpg');
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(false);
        $organizer = $this->preflightOrganizer($authorization, $asset);

        self::assertSame(
            'Source asset mutation is not permitted.',
            $organizer->preflightApply([$this->pendingOperation()]),
        );
    }

    #[Test]
    public function preflightApplyRejectsAnUnauthorizedTargetBeforeMutation(): void
    {
        $asset = $this->asset('/source/f.jpg');
        $parent = $this->createMock(Asset\Folder::class);
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn ($element, string $permission): bool => $element === $asset && in_array($permission, ['view', 'publish'], true),
        );
        $organizer = $this->preflightOrganizer($authorization, $asset, $parent);

        self::assertSame(
            'Target path creation is not permitted.',
            $organizer->preflightApply([$this->pendingOperation()]),
        );
    }

    #[Test]
    public function preflightApplyAcceptsEveryAuthorizedMutationBoundary(): void
    {
        $asset = $this->asset('/source/f.jpg');
        $parent = $this->createMock(Asset\Folder::class);
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);
        $organizer = $this->preflightOrganizer($authorization, $asset, $parent);

        self::assertNull($organizer->preflightApply([$this->pendingOperation()]));
    }

    #[Test]
    public function executeMoveSkipsWhenTheLockIsUnavailableAndReleasesNothing(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturn(false);
        $loopGuard->expects(self::never())->method('releaseAsset');

        $result = $this->executeMove($loopGuard, MovePlan::proceed('/target', 'f.jpg', '/target/f.jpg'));

        self::assertSame(OperationStatus::Skipped, $result->status);
    }

    #[Test]
    public function executeMoveWithASkipPlanNeverAcquiresTheAssetLock(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->expects(self::never())->method('acquireAsset');

        $result = $this->executeMove($loopGuard, MovePlan::skip('/target/f.jpg', 'Asset is locked'));

        self::assertSame(OperationStatus::Skipped, $result->status);
    }

    #[Test]
    public function executeMoveRethrowsRetryableInfrastructureFailures(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturn(true);
        $loopGuard->method('acquireTarget')->willReturn(true);
        $loopGuard->method('refreshAsset')->willThrowException(new LockStorageException('Redis unavailable.'));
        $loopGuard->expects(self::once())->method('releaseTarget')->with('/target/f.jpg');
        $loopGuard->expects(self::once())->method('releaseAsset')->with(1);

        $this->expectException(LockStorageException::class);

        $this->executeMove($loopGuard, MovePlan::proceed('/target', 'f.jpg', '/target/f.jpg'));
    }

    #[Test]
    public function moveOperationRecordsTheScopedActorUser(): void
    {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn(ActorContext::user(27));

        $result = $this->executeMove(
            $this->createMock(LoopGuard::class),
            MovePlan::skip('/target/f.jpg', 'Preview only'),
            authorization: $authorization,
        );

        self::assertSame(27, $result->operation?->userId);
    }

    #[Test]
    public function executeMoveSkipsWhenTheTargetLockIsUnavailable(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturn(true);
        $loopGuard->method('acquireTarget')->willReturn(false);
        $loopGuard->expects(self::once())->method('releaseAsset')->with(1);
        $loopGuard->expects(self::never())->method('releaseTarget');

        $result = $this->executeMove($loopGuard, MovePlan::proceed('/target', 'f.jpg', '/target/f.jpg'));

        self::assertSame(OperationStatus::Skipped, $result->status);
        self::assertSame('Target path is being allocated by another job', $result->message);
    }

    #[Test]
    public function executeMoveRechecksTheTargetAfterAcquiringItsLock(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturn(true);
        $loopGuard->method('acquireTarget')->willReturn(true);
        $loopGuard->expects(self::once())->method('releaseTarget')->with('/target/f.jpg');
        $loopGuard->expects(self::once())->method('releaseAsset')->with(1);

        $result = $this->executeMove(
            $loopGuard,
            MovePlan::proceed('/target', 'f.jpg', '/target/f.jpg'),
            $this->asset('/target/f.jpg'),
        );

        self::assertSame(OperationStatus::Skipped, $result->status);
        self::assertSame('Target path is no longer available', $result->message);
    }

    #[Test]
    public function executeMoveChecksCreatePermissionBeforeCreatingTargetFolders(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturn(true);
        $loopGuard->method('acquireTarget')->willReturn(true);
        $loopGuard->expects(self::once())->method('releaseTarget')->with('/target/f.jpg');
        $loopGuard->expects(self::once())->method('releaseAsset')->with(1);

        $parent = $this->createMock(Asset\Folder::class);
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn(ActorContext::system());
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (Asset $element, string $permission): bool => $permission !== 'create',
        );

        $result = $this->executeMove(
            $loopGuard,
            MovePlan::proceed('/target', 'f.jpg', '/target/f.jpg'),
            targetParent: $parent,
            authorization: $authorization,
        );

        self::assertSame(OperationStatus::Skipped, $result->status);
        self::assertSame('Not permitted to create the target path', $result->message);
    }

    #[Test]
    public function executeMoveSkipsAnAssetThatBecameProtectedBetweenPlanningAndTheAssetLock(): void
    {
        // a user locks the asset after MovePlanner planned it; the organizer reloads under the
        // asset lock and must skip as protected, never taking the target lock or moving it.
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturn(true);
        $loopGuard->expects(self::once())->method('releaseAsset')->with(1);
        $loopGuard->expects(self::never())->method('acquireTarget');

        $result = $this->executeMove(
            $loopGuard,
            MovePlan::proceed('/target', 'f.jpg', '/target/f.jpg'),
            movedAssetLocked: true,
        );

        self::assertSame(OperationStatus::Skipped, $result->status);
        self::assertSame('Asset is protected from automated changes', $result->message);
    }

    #[Test]
    public function postMoveObserverFailureDoesNotMisreportTheCommittedMoveAsFailed(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturn(true);
        $loopGuard->method('acquireTarget')->willReturn(true);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(AssetPilotEvents::POST_MOVE, static fn (): never => throw new \RuntimeException('observer unavailable'));
        $journal = $this->createMock(OperationJournalInterface::class);
        $journal->method('begin')->willReturnCallback(
            static fn (OperationIntent $intent): OperationHandle => new OperationHandle(73, $intent),
        );
        $journal->expects(self::once())->method('complete')->with(
            self::callback(static fn (OperationHandle $handle): bool => $handle->operationId === 73),
            OperationStatus::CompletedWithObserverError,
            'Post-move observer delivery failed.',
            self::isType('int'),
        )->willReturn(true);

        $result = $this->executeMove(
            $loopGuard,
            MovePlan::proceed('/target', 'f.jpg', '/target/f.jpg'),
            journal: $journal,
            dispatcher: $dispatcher,
        );

        self::assertSame(OperationStatus::CompletedWithObserverError, $result->status);
        self::assertSame('Post-move observer delivery failed.', $result->message);
    }

    #[Test]
    public function moveCompletesTheSameDurableAuditEntryThatWasStartedBeforeSave(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturn(true);
        $loopGuard->method('acquireTarget')->willReturn(true);
        $journal = $this->createMock(OperationJournalInterface::class);
        $journal->expects(self::once())
            ->method('begin')
            ->with(self::callback(static fn (OperationIntent $intent): bool => $intent->sourcePath === '/source/f.jpg'))
            ->willReturnCallback(static fn (OperationIntent $intent): OperationHandle => new OperationHandle(73, $intent));
        $journal->expects(self::once())
            ->method('complete')
            ->with(self::callback(static fn (OperationHandle $handle): bool => $handle->operationId === 73), OperationStatus::Completed, null, self::isType('int'))
            ->willReturn(true);

        $result = $this->executeMove(
            $loopGuard,
            MovePlan::proceed('/target', 'f.jpg', '/target/f.jpg'),
            journal: $journal,
        );

        self::assertSame(OperationStatus::Completed, $result->status);
    }

    #[Test]
    public function journalCompletionFailureMarksTheCommittedMoveForRecovery(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturn(true);
        $loopGuard->method('acquireTarget')->willReturn(true);
        $journal = $this->createMock(OperationJournalInterface::class);
        $journal->method('begin')->willReturnCallback(
            static fn (OperationIntent $intent): OperationHandle => new OperationHandle(73, $intent),
        );
        $journal->method('complete')->willThrowException(new \RuntimeException('database unavailable'));

        $result = $this->executeMove(
            $loopGuard,
            MovePlan::proceed('/target', 'f.jpg', '/target/f.jpg'),
            journal: $journal,
        );

        self::assertSame(OperationStatus::RecoveryRequired, $result->status);
        self::assertSame('The asset move committed, but its operation journal requires recovery.', $result->message);
    }

    #[Test]
    public function dryRunSelectsTheHighestPriorityRuleAcrossEveryField(): void
    {
        $object = $this->createMock(\Pimcore\Model\DataObject\Concrete::class);
        $object->method('getId')->willReturn(7);
        $object->method('getClassName')->willReturn('Product');

        $asset = $this->asset('/source/f.jpg');
        $low = Rule::fromConfig('low', ['class' => 'Product', 'fields' => ['first'], 'target_path' => '/low', 'priority' => 10]);
        $high = Rule::fromConfig('high', ['class' => 'Product', 'fields' => ['second'], 'target_path' => '/high', 'priority' => 100]);

        $engine = $this->createMock(RuleEngineInterface::class);
        $engine->method('matchField')->willReturnMap([
            [$object, $asset, 'first', null, [new RuleMatch($low, $object, $asset, '/low')]],
            [$object, $asset, 'second', null, [new RuleMatch($high, $object, $asset, '/high')]],
        ]);

        $extractor = $this->createMock(AssetFieldExtractorInterface::class);
        $extractor->method('extract')->willReturn([
            new AssetFieldInfo('first', null, 'image', [$asset]),
            new AssetFieldInfo('second', null, 'image', [$asset]),
        ]);

        $planner = $this->createMock(MovePlanner::class);
        $planner->expects(self::once())
            ->method('plan')
            ->with($asset, $object, $high, '/high', TriggerType::Manual, true)
            ->willReturn(MovePlan::proceed('/high', 'f.jpg', '/high/f.jpg'));

        $organizer = new AssetOrganizer(
            $engine,
            $extractor,
            $planner,
            $this->createMock(AuditWriterInterface::class),
            $this->journal(),
            new EventDispatcher(),
            $this->createMock(LoopGuard::class),
            new NullLogger(),
            $this->authorization(),
            new OrganizePlanFingerprint(),
            new LoopGuardedAssetSaver($this->createMock(LoopGuard::class)),
        );

        $operations = $organizer->dryRun($object);

        self::assertCount(1, $operations);
        self::assertSame('high', $operations[0]->ruleName);
        self::assertSame('/high/f.jpg', $operations[0]->targetPath);
    }

    #[Test]
    public function analyzeDriftReturnsBlockedMismatchesWithoutPlanningAMove(): void
    {
        $object = $this->createMock(Concrete::class);
        $object->method('getId')->willReturn(7);
        $asset = $this->asset('/source/f.jpg');
        $rule = Rule::fromConfig('images', ['class' => 'Product', 'fields' => ['images'], 'target_path' => '/target']);
        $engine = $this->createMock(RuleEngineInterface::class);
        $engine->method('matchField')->willReturn([new RuleMatch($rule, $object, $asset, '/target')]);
        $extractor = $this->createMock(AssetFieldExtractorInterface::class);
        $extractor->method('extract')->willReturn([new AssetFieldInfo('images', null, 'image', [$asset])]);
        $planner = $this->createMock(MovePlanner::class);
        $planner->expects(self::never())->method('plan');
        $planner->expects(self::once())->method('assessDrift')->willReturn(new DriftAssessment('/target/f.jpg', DriftEligibility::Blocked, 'Asset is locked'));
        $organizer = new AssetOrganizer(
            $engine,
            $extractor,
            $planner,
            $this->createMock(AuditWriterInterface::class),
            $this->journal(),
            new EventDispatcher(),
            $this->createMock(LoopGuard::class),
            new NullLogger(),
            $this->authorization(),
            new OrganizePlanFingerprint(),
            new LoopGuardedAssetSaver($this->createMock(LoopGuard::class)),
        );

        $items = $organizer->analyzeDrift($object);

        self::assertCount(1, $items);
        self::assertSame(DriftEligibility::Blocked, $items[0]->eligibility);
        self::assertSame('Asset is locked', $items[0]->reason);
    }

    #[Test]
    public function firstAssignmentIsRecordedOnTheAssetBeforeSave(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(1);
        $asset->method('getRealFullPath')->willReturn('/source/f.jpg');
        $asset->method('getProperty')->with(FirstAssignmentStrategy::ASSIGNMENT_PROPERTY)->willReturn(null);
        $asset->expects(self::once())->method('setProperty')->with(FirstAssignmentStrategy::ASSIGNMENT_PROPERTY, 'bool', true);
        $asset->expects(self::once())->method('save');

        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturn(true);
        $loopGuard->method('acquireTarget')->willReturn(true);
        $loopGuard->expects(self::once())->method('releaseAsset')->with(1);
        $loopGuard->expects(self::once())->method('releaseTarget')->with('/target/f.jpg');

        $folder = $this->createMock(Asset\Folder::class);
        $audit = $this->createMock(AuditWriterInterface::class);
        $organizer = new class (
            $this->createMock(RuleEngine::class),
            $this->createMock(AssetFieldExtractor::class),
            $this->createMock(MovePlanner::class),
            $audit,
            $this->journal(),
            new EventDispatcher(),
            $loopGuard,
            new NullLogger(),
            $this->authorization(),
            $asset,
            $folder,
        ) extends AssetOrganizer {
            public function __construct(
                RuleEngine $engine,
                AssetFieldExtractor $extractor,
                MovePlanner $planner,
                AuditWriterInterface $audit,
                OperationJournalInterface $journal,
                EventDispatcher $dispatcher,
                LoopGuard $loopGuard,
                NullLogger $logger,
                ElementAuthorization $authorization,
                private readonly Asset $liveAsset,
                private readonly Asset\Folder $folder,
            ) {
                parent::__construct($engine, $extractor, $planner, $audit, $journal, $dispatcher, $loopGuard, $logger, $authorization, new OrganizePlanFingerprint(), new LoopGuardedAssetSaver($loopGuard));
            }

            public function run(Asset $asset, MovePlan $plan, AbstractObject $object, Rule $rule): OperationResult
            {
                return $this->executeMove($asset, $plan, $object, $rule, TriggerType::Manual);
            }

            protected function reloadAsset(Asset $asset): ?Asset
            {
                return $this->liveAsset;
            }

            protected function createFolderIfNeeded(string $path): Asset\Folder
            {
                return $this->folder;
            }

            protected function loadAssetAtPath(string $path): ?Asset
            {
                return null;
            }

            protected function nearestExistingFolder(string $path): ?Asset\Folder
            {
                return null;
            }

            protected function classifyPersistedMove(int $assetId, string $sourcePath, string $targetPath, bool $requiresFirstAssignment): OperationStatus
            {
                return OperationStatus::Completed;
            }
        };

        $rule = new Rule(
            name: 'first',
            class: 'Product',
            fields: [],
            condition: null,
            targetPath: '/target',
            strategy: MoveStrategy::FirstAssignment,
            callback: null,
            priority: 10,
            enabled: true,
            filters: [],
        );

        $result = $organizer->run(
            $asset,
            MovePlan::proceed('/target', 'f.jpg', '/target/f.jpg'),
            $this->createMock(AbstractObject::class),
            $rule,
        );

        self::assertSame(OperationStatus::Completed, $result->status);
    }

    private function executeMove(
        LoopGuard $loopGuard,
        MovePlan $plan,
        ?Asset $assetAtTarget = null,
        ?Asset\Folder $targetParent = null,
        ?ElementAuthorization $authorization = null,
        ?AuditWriterInterface $audit = null,
        ?OperationJournalInterface $journal = null,
        ?EventDispatcher $dispatcher = null,
        bool $movedAssetLocked = false,
    ): OperationResult {
        $authorization ??= $this->authorization();
        if ($audit === null) {
            $audit = $this->createMock(AuditWriterInterface::class);
        }
        $journal ??= $this->journal();
        $dispatcher ??= new EventDispatcher();
        $folder = $this->createMock(Asset\Folder::class);
        $organizer = new class (
            $this->createMock(RuleEngine::class),
            $this->createMock(AssetFieldExtractor::class),
            $this->createMock(MovePlanner::class),
            $audit,
            $journal,
            $dispatcher,
            $loopGuard,
            new NullLogger(),
            $authorization,
            $assetAtTarget,
            $targetParent,
            $folder,
        ) extends AssetOrganizer {
            public function __construct(
                RuleEngine $engine,
                AssetFieldExtractor $extractor,
                MovePlanner $planner,
                AuditWriterInterface $audit,
                OperationJournalInterface $journal,
                EventDispatcher $dispatcher,
                LoopGuard $loopGuard,
                NullLogger $logger,
                ElementAuthorization $authorization,
                private readonly ?Asset $assetAtTarget,
                private readonly ?Asset\Folder $targetParent,
                private readonly Asset\Folder $folder,
            ) {
                parent::__construct($engine, $extractor, $planner, $audit, $journal, $dispatcher, $loopGuard, $logger, $authorization, new OrganizePlanFingerprint(), new LoopGuardedAssetSaver($loopGuard));
            }

            public function runMove(Asset $a, MovePlan $p, AbstractObject $o, Rule $r): OperationResult
            {
                return $this->executeMove($a, $p, $o, $r, TriggerType::Manual);
            }

            protected function reloadAsset(Asset $asset): ?Asset
            {
                return $asset;
            }

            protected function loadAssetAtPath(string $path): ?Asset
            {
                return $this->assetAtTarget;
            }

            protected function nearestExistingFolder(string $path): ?Asset\Folder
            {
                return $this->targetParent;
            }

            protected function createFolderIfNeeded(string $path): Asset\Folder
            {
                return $this->folder;
            }

            protected function classifyPersistedMove(int $assetId, string $sourcePath, string $targetPath, bool $requiresFirstAssignment): OperationStatus
            {
                return OperationStatus::Completed;
            }
        };

        return $organizer->runMove(
            $this->asset('/source/f.jpg', $movedAssetLocked),
            $plan,
            $this->createMock(AbstractObject::class),
            Rule::fromConfig('r', ['class' => 'Product', 'target_path' => '/target']),
        );
    }

    private function asset(string $path, bool $locked = false): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(1);
        $asset->method('getRealFullPath')->willReturn($path);
        $asset->method('hasProperty')->willReturn($locked);
        $asset->method('getProperty')->willReturn($locked ? true : null);

        return $asset;
    }

    private function pendingOperation(): \Oronts\AssetPilotBundle\Model\MoveOperation
    {
        return new \Oronts\AssetPilotBundle\Model\MoveOperation(
            assetId: 1,
            sourcePath: '/source/f.jpg',
            targetPath: '/target/f.jpg',
            objectId: 7,
            objectClass: 'Product',
            ruleName: 'images',
            status: OperationStatus::Pending,
            triggerType: TriggerType::Api,
        );
    }

    private function preflightOrganizer(
        ElementAuthorization $authorization,
        ?Asset $asset,
        ?Asset\Folder $targetParent = null,
    ): AssetOrganizer {
        return new class (
            $this->createMock(RuleEngineInterface::class),
            $this->createMock(AssetFieldExtractorInterface::class),
            $this->createMock(MovePlanner::class),
            $this->createMock(AuditWriterInterface::class),
            $this->journal(),
            new EventDispatcher(),
            $this->createMock(LoopGuard::class),
            new NullLogger(),
            $authorization,
            $asset,
            $targetParent,
        ) extends AssetOrganizer {
            public function __construct(
                RuleEngineInterface $engine,
                AssetFieldExtractorInterface $extractor,
                MovePlanner $planner,
                AuditWriterInterface $audit,
                OperationJournalInterface $journal,
                EventDispatcher $dispatcher,
                LoopGuard $loopGuard,
                NullLogger $logger,
                ElementAuthorization $authorization,
                private readonly ?Asset $asset,
                private readonly ?Asset\Folder $targetParent,
            ) {
                parent::__construct($engine, $extractor, $planner, $audit, $journal, $dispatcher, $loopGuard, $logger, $authorization, new OrganizePlanFingerprint(), new LoopGuardedAssetSaver($loopGuard));
            }

            protected function loadAssetById(int $assetId): ?Asset
            {
                return $this->asset;
            }

            protected function nearestExistingFolder(string $path): ?Asset\Folder
            {
                return $this->targetParent;
            }
        };
    }

    private function authorization(): ElementAuthorization
    {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn(ActorContext::system());
        $authorization->method('isAllowed')->willReturn(true);

        return $authorization;
    }

    private function reviewedObject(int $id): Concrete
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

    private function reviewedPendingOperation(int $assetId, string $targetPath): MoveOperation
    {
        return new MoveOperation($assetId, '/in/' . $assetId . '.jpg', $targetPath, 42, 'Product', 'r', OperationStatus::Pending, TriggerType::Api);
    }

    /** @param list<list<MoveOperation>> $dryRunResults consecutive dryRun() results (pre-lock, under-lock) */
    private function reviewedOrganizer(AbstractObject $object, LoopGuard $loopGuard, OrganizePlanFingerprint $fingerprints, array $dryRunResults): AssetOrganizer
    {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);

        return new class (
            $this->createMock(RuleEngineInterface::class),
            $this->createMock(AssetFieldExtractorInterface::class),
            $this->createMock(MovePlanner::class),
            $this->createMock(AuditWriterInterface::class),
            $this->journal(),
            new EventDispatcher(),
            $loopGuard,
            new NullLogger(),
            $authorization,
            $fingerprints,
            $object,
            $dryRunResults,
        ) extends AssetOrganizer {
            private int $dryRunCalls = 0;

            /** @param list<list<MoveOperation>> $dryRunResults */
            public function __construct(
                RuleEngineInterface $engine,
                AssetFieldExtractorInterface $extractor,
                MovePlanner $planner,
                AuditWriterInterface $audit,
                OperationJournalInterface $journal,
                EventDispatcher $dispatcher,
                LoopGuard $loopGuard,
                NullLogger $logger,
                ElementAuthorization $authorization,
                OrganizePlanFingerprint $fingerprints,
                private readonly AbstractObject $live,
                private readonly array $dryRunResults,
            ) {
                parent::__construct($engine, $extractor, $planner, $audit, $journal, $dispatcher, $loopGuard, $logger, $authorization, $fingerprints, new LoopGuardedAssetSaver($loopGuard));
            }

            protected function reloadObject(int $objectId): ?AbstractObject
            {
                return $this->live;
            }

            public function dryRun(AbstractObject $object, TriggerType $triggerType = TriggerType::Manual, ?string $ruleName = null): array
            {
                $result = $this->dryRunResults[$this->dryRunCalls] ?? [];
                ++$this->dryRunCalls;

                return $result;
            }
        };
    }

    private function journal(): OperationJournalInterface
    {
        $journal = $this->createMock(OperationJournalInterface::class);
        $journal->method('begin')->willReturnCallback(
            static fn (OperationIntent $intent): OperationHandle => new OperationHandle(73, $intent),
        );
        $journal->method('complete')->willReturn(true);

        return $journal;
    }
}
