<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Enum\DuplicateMergePhase;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Exception\NotPermittedException;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Merge\CopyDisposition;
use Oronts\AssetPilotBundle\Merge\DuplicateMergeContextInterface;
use Oronts\AssetPilotBundle\Merge\DuplicateMergeStrategyInterface;
use Oronts\AssetPilotBundle\Merge\MergeOutcome;
use Oronts\AssetPilotBundle\Merge\ReferrerSnapshot;
use Oronts\AssetPilotBundle\Merge\RepointPreflight;
use Oronts\AssetPilotBundle\Merge\RepointReport;
use Oronts\AssetPilotBundle\Merge\ResumableDuplicateMergeStrategyInterface;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\DuplicateGroup;
use Oronts\AssetPilotBundle\Security\ActorContextProvider;
use Oronts\AssetPilotBundle\Security\ActorContextStore;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\DuplicateMergeService;
use Oronts\AssetPilotBundle\Service\DuplicateReferenceRepointer;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\OperationRunStore;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[CoversClass(DuplicateMergeService::class)]
class DuplicateMergeServiceTest extends TestCase
{
    /** @param \ArrayObject<int, int>|null $disposed records copy ids handed to the strategy */
    private function strategy(string $name, ?\ArrayObject $disposed = null, bool $repoints = true): DuplicateMergeStrategyInterface
    {
        return new class ($name, $disposed, $repoints) implements DuplicateMergeStrategyInterface {
            public function __construct(private readonly string $n, private readonly ?\ArrayObject $disposed, private readonly bool $repoints) {}

            public function name(): string
            {
                return $this->n;
            }

            public function repointsReferences(): bool
            {
                return $this->repoints;
            }

            public function disposeCopy(RepointReport $report, DuplicateMergeContextInterface $context): CopyDisposition
            {
                $copyId = $context->copyId();
                $this->disposed?->append($copyId);

                return new CopyDisposition($copyId, DispositionOutcome::Quarantined);
            }
        };
    }

    /**
     * @param list<DuplicateMergeStrategyInterface> $strategies
     */
    private function service(
        DuplicateReferenceRepointer $repointer,
        array $strategies,
        string $liveChecksum = 'abc',
        bool $isAuthorized = true,
        ?LoopGuard $loopGuard = null,
        ?OperationRunStoreInterface $runs = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?\Closure $preflight = null,
        array $assets = [],
    ): DuplicateMergeService {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE dependencies (targettype TEXT, targetid INTEGER, sourcetype TEXT, sourceid INTEGER)');
        $schema = new Schema();
        Installer::ensureCurrentSchema($schema);
        $manager = $connection->createSchemaManager();
        $manager->createTable($schema->getTable(Installer::TABLE_OPERATION_RUN));
        $manager->createTable($schema->getTable(Installer::TABLE_OPERATION_RUN_ITEM));
        $provider = $this->createMock(ActorContextProvider::class);
        $provider->method('current')->willReturn(ActorContext::system());
        $actors = new ActorContextStore($provider);
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturnCallback($actors->current(...));
        $loopGuard ??= new LoopGuard(new ArrayAdapter(), new LockFactory(new InMemoryStore()));
        $runs ??= new OperationRunStore($connection);
        $eventDispatcher ??= new EventDispatcher();
        $preflight ??= static fn (int $from, int $to): RepointPreflight => new RepointPreflight($from, $to, []);
        $repointer->method('preflight')->willReturnCallback($preflight);

        return new class (
            $strategies,
            $repointer,
            $connection,
            $authorization,
            $actors,
            $loopGuard,
            $runs,
            $eventDispatcher,
            $liveChecksum,
            $isAuthorized,
            $assets,
        ) extends DuplicateMergeService {
            public function __construct(
                iterable $strategies,
                DuplicateReferenceRepointer $repointer,
                Connection $connection,
                ElementAuthorization $authorization,
                ActorContextStore $actors,
                LoopGuard $loopGuard,
                OperationRunStoreInterface $runs,
                EventDispatcherInterface $eventDispatcher,
                private readonly string $live,
                private readonly bool $allowed,
                private readonly array $assets,
            ) {
                parent::__construct(
                    $strategies,
                    $repointer,
                    new NullLogger(),
                    $connection,
                    $authorization,
                    $actors,
                    $loopGuard,
                    $runs,
                    $eventDispatcher,
                    'quarantine',
                );
            }

            protected function loadAsset(int $assetId): ?\Pimcore\Model\Asset
            {
                return $this->assets[$assetId] ?? null;
            }

            protected function liveChecksum(int $assetId): ?string
            {
                return $this->live;
            }

            protected function assetIdentityFingerprint(int $assetId): string
            {
                return hash('sha256', sprintf(
                    'asset:%d:%s:%s',
                    $assetId,
                    $this->live,
                    $this->assetIsProtected($assetId) ? 'protected' : 'mutable',
                ));
            }

            protected function assetAllows(int $assetId, string $permission): bool
            {
                TestCase::assertContains($permission, ['view', 'publish']);

                return $this->allowed;
            }
        };
    }

    private function applyReviewed(
        DuplicateMergeService $service,
        DuplicateGroup $group,
        ?int $canonicalId = null,
        ?string $strategyName = null,
    ): MergeOutcome {
        $canonicalId ??= min($group->assetIds);

        return $service->merge($group, $service->fingerprintMap($group, $canonicalId), $canonicalId, $strategyName);
    }

    #[Test]
    public function protectionStateChangesTheReviewedFingerprint(): void
    {
        $group = new DuplicateGroup('abc', 100, 2, [3, 9]);
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        $strategies = [$this->strategy('quarantine')];

        $unlocked = $this->service($repointer, $strategies, assets: [
            3 => $this->fingerprintAsset(3, false),
            9 => $this->fingerprintAsset(9, false),
        ])->fingerprintMap($group, 3);
        $locked = $this->service($repointer, $strategies, assets: [
            3 => $this->fingerprintAsset(3, false),
            9 => $this->fingerprintAsset(9, true),
        ])->fingerprintMap($group, 3);

        self::assertSame($unlocked['asset:3'], $locked['asset:3']);
        self::assertNotSame($unlocked['asset:9'], $locked['asset:9']);
    }

    #[Test]
    public function referrerContentChangesTheReviewedCopyFingerprint(): void
    {
        $referrerFingerprint = 'object-v1';
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        $service = $this->service(
            $repointer,
            [$this->strategy('quarantine')],
            preflight: static function (int $from, int $to) use (&$referrerFingerprint): RepointPreflight {
                return new RepointPreflight(
                    $from,
                    $to,
                    [new ReferrerSnapshot('object', 41, $referrerFingerprint)],
                );
            },
            assets: [
                3 => $this->fingerprintAsset(3, false),
                9 => $this->fingerprintAsset(9, false),
            ],
        );
        $group = new DuplicateGroup('abc', 100, 2, [3, 9]);
        $before = $service->fingerprintMap($group, 3);

        $referrerFingerprint = 'object-v2';
        $after = $service->fingerprintMap($group, 3);

        self::assertSame($before['asset:3'], $after['asset:3']);
        self::assertNotSame($before['asset:9'], $after['asset:9']);
    }

    #[Test]
    public function protectedCopyIsRejectedBeforeReferencesAreRepointed(): void
    {
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        $repointer->expects(self::never())->method('repoint');

        $group = new DuplicateGroup('abc', 100, 2, [3, 9]);
        $service = $this->service($repointer, [$this->strategy('quarantine')], assets: [
            3 => $this->fingerprintAsset(3, false),
            9 => $this->fingerprintAsset(9, true),
        ]);
        $outcome = $this->applyReviewed($service, $group);

        self::assertSame(DispositionOutcome::Blocked, $outcome->dispositions[0]->outcome);
        self::assertStringContainsString('protected', (string) $outcome->dispositions[0]->reason);
    }

    #[Test]
    public function changedGroupStateAfterEntryIsRejectedUnderLocksBeforeRepointing(): void
    {
        $group = new DuplicateGroup('abc', 100, 2, [3, 9]);
        $path = '/assets/9.jpg';
        $preflightCalls = 0;
        $copy = $this->createMock(\Pimcore\Model\Asset::class);
        $copy->method('getId')->willReturn(9);
        $copy->method('getChecksum')->willReturn('abc');
        $copy->method('getModificationDate')->willReturn(1_000);
        $copy->method('hasProperty')->willReturn(false);
        $copy->method('getRealFullPath')->willReturnCallback(static function () use (&$path): string {
            return $path;
        });
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        $repointer->expects(self::never())->method('repoint');
        $service = $this->service(
            $repointer,
            [$this->strategy('quarantine')],
            preflight: static function (int $from, int $to) use (&$path, &$preflightCalls): RepointPreflight {
                if (++$preflightCalls === 4) {
                    $path = '/changed/9.jpg';
                }

                return new RepointPreflight($from, $to, []);
            },
            assets: [
                3 => $this->fingerprintAsset(3, false),
                9 => $copy,
            ],
        );
        $reviewedFingerprints = $service->fingerprintMap($group, 3);

        $this->expectException(\Oronts\AssetPilotBundle\Exception\StaleApplyPlanException::class);
        $this->expectExceptionMessage('changed after preview');

        $service->merge($group, $reviewedFingerprints);
    }

    private function fingerprintAsset(int $id, bool $locked): \Pimcore\Model\Asset
    {
        $asset = $this->createMock(\Pimcore\Model\Asset::class);
        $asset->method('getId')->willReturn($id);
        $asset->method('getChecksum')->willReturn('abc');
        $asset->method('getModificationDate')->willReturn(1_000);
        $asset->method('getRealFullPath')->willReturn('/assets/' . $id . '.jpg');
        $asset->method('hasProperty')->willReturn($locked);
        $asset->method('getProperty')->willReturn($locked);

        return $asset;
    }


    /** @return array{OperationRunStore, Connection} */
    private function runStore(): array
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new Schema();
        Installer::ensureCurrentSchema($schema);
        $manager = $connection->createSchemaManager();
        $manager->createTable($schema->getTable(Installer::TABLE_OPERATION_RUN));
        $manager->createTable($schema->getTable(Installer::TABLE_OPERATION_RUN_ITEM));

        return [new OperationRunStore($connection), $connection];
    }

    #[Test]
    public function repointsEveryCopyOntoTheLowestIdCanonicalAndDisposesThem(): void
    {
        $disposed = new \ArrayObject();
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        // Canonical is 3 (lowest id); copies 7 and 9 are each repointed onto it.
        $repointer->expects(self::exactly(2))->method('repoint')
            ->willReturnCallback(static fn (int $from, int $to, bool $dry): RepointReport => new RepointReport($from, $to, 1, []));

        $group = new DuplicateGroup('abc', 100, 3, [7, 3, 9]);
        $outcome = $this->applyReviewed($this->service($repointer, [$this->strategy('quarantine', $disposed)]), $group);

        self::assertSame(3, $outcome->canonicalId);
        self::assertSame([7, 9], $disposed->getArrayCopy());
        self::assertCount(2, $outcome->dispositions);
        self::assertSame(DispositionOutcome::Quarantined, $outcome->dispositions[0]->outcome);
    }

    #[Test]
    public function passesTheFencedExecutionContextToEveryStrategy(): void
    {
        $captured = new \ArrayObject();
        $strategy = new class ('quarantine', $captured) implements DuplicateMergeStrategyInterface {
            public function __construct(private readonly string $n, private readonly \ArrayObject $captured) {}

            public function name(): string
            {
                return $this->n;
            }

            public function repointsReferences(): bool
            {
                return true;
            }

            public function disposeCopy(RepointReport $report, DuplicateMergeContextInterface $context): CopyDisposition
            {
                $context->heartbeat();
                $this->captured->append($context);

                return new CopyDisposition($context->copyId(), DispositionOutcome::Quarantined);
            }
        };
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        $repointer->method('repoint')->willReturnCallback(static fn (int $from, int $to, bool $dry): RepointReport => new RepointReport($from, $to, 1, []));

        $this->applyReviewed($this->service($repointer, [$strategy]), new DuplicateGroup('abc', 100, 2, [3, 9]));

        self::assertCount(1, $captured);
        $context = $captured[0];
        self::assertInstanceOf(DuplicateMergeContextInterface::class, $context);
        self::assertSame(9, $context->copyId());
        self::assertSame(3, $context->canonicalId());
        self::assertSame('asset:9', $context->itemKey());
        self::assertMatchesRegularExpression(
            '/^duplicate-merge:[^:]+:abc:9$/',
            $context->idempotencyKey(),
            'the idempotency key carries the run-scoped root-operation id, so an independent later merge of the same copy does not collide',
        );
    }

    #[Test]
    public function firstCopyDispositionDoesNotInvalidateLaterReviewedCopies(): void
    {
        $paths = new \ArrayObject([
            7 => '/assets/7.jpg',
            9 => '/assets/9.jpg',
        ]);
        $asset = function (int $id) use ($paths): \Pimcore\Model\Asset {
            $value = $this->createMock(\Pimcore\Model\Asset::class);
            $value->method('getId')->willReturn($id);
            $value->method('getChecksum')->willReturn('abc');
            $value->method('getModificationDate')->willReturn(1_000);
            $value->method('getRealFullPath')->willReturnCallback(static fn (): string => (string) $paths[$id]);
            $value->method('hasProperty')->willReturn(false);

            return $value;
        };
        $strategy = new class ($paths) implements DuplicateMergeStrategyInterface {
            public function __construct(private readonly \ArrayObject $paths) {}

            public function name(): string
            {
                return 'quarantine';
            }

            public function repointsReferences(): bool
            {
                return true;
            }

            public function disposeCopy(RepointReport $report, DuplicateMergeContextInterface $context): CopyDisposition
            {
                $copyId = $context->copyId();
                $this->paths[$copyId] = '/quarantine/' . $copyId . '.jpg';

                return new CopyDisposition($copyId, DispositionOutcome::Quarantined);
            }
        };
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        $repointer->expects(self::exactly(2))->method('repoint')
            ->willReturnCallback(static fn (int $from, int $to): RepointReport => new RepointReport($from, $to, 0, []));
        $group = new DuplicateGroup('abc', 100, 3, [3, 7, 9]);
        $service = $this->service($repointer, [$strategy], assets: [
            3 => $this->fingerprintAsset(3, false),
            7 => $asset(7),
            9 => $asset(9),
        ]);

        $outcome = $this->applyReviewed($service, $group);

        self::assertSame(
            [DispositionOutcome::Quarantined, DispositionOutcome::Quarantined],
            array_map(static fn (CopyDisposition $copy): DispositionOutcome => $copy->outcome, $outcome->dispositions),
        );
    }

    #[Test]
    public function doesNotRepointWhenTheStrategyOptsOut(): void
    {
        $disposed = new \ArrayObject();
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        // A non-repointing strategy (e.g. isolate) must leave references intact: the repointer never runs.
        $repointer->expects(self::never())->method('repoint');

        $group = new DuplicateGroup('abc', 100, 2, [3, 9]);
        $outcome = $this->applyReviewed($this->service($repointer, [$this->strategy('isolate', $disposed, repoints: false)]), $group, strategyName: 'isolate');

        self::assertSame([9], $disposed->getArrayCopy(), 'the copy is still handed to the strategy for disposal');
        self::assertSame(3, $outcome->canonicalId);
    }

    #[Test]
    public function honoursAnExplicitCanonicalWhenItIsAMemberOfTheGroup(): void
    {
        $disposed = new \ArrayObject();
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        $repointer->method('repoint')->willReturnCallback(static fn (int $from, int $to, bool $dry): RepointReport => new RepointReport($from, $to, 0, []));

        $group = new DuplicateGroup('abc', 100, 3, [7, 3, 9]);
        $outcome = $this->applyReviewed($this->service($repointer, [$this->strategy('quarantine', $disposed)]), $group, canonicalId: 9);

        self::assertSame(9, $outcome->canonicalId);
        self::assertSame([7, 3], $disposed->getArrayCopy());
    }

    #[Test]
    public function dryRunRepointsAndDisposesNothingButReportsThePlan(): void
    {
        $disposed = new \ArrayObject();
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        $repointer->method('repoint')->willReturnCallback(static fn (int $from, int $to, bool $dry): RepointReport => new RepointReport($from, $to, 1, []));

        $group = new DuplicateGroup('abc', 100, 2, [3, 9]);
        $outcome = $this->service($repointer, [$this->strategy('quarantine', $disposed)])->preview($group);

        self::assertSame([], $disposed->getArrayCopy(), 'a dry run must not dispose any copy');
        self::assertSame(DispositionOutcome::Skipped, $outcome->dispositions[0]->outcome);
    }

    #[Test]
    public function refusesToMergeWhenAnyAssetIsOutsideTheActorWorkspace(): void
    {
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        $repointer->expects(self::never())->method('repoint');
        $service = $this->service($repointer, [$this->strategy('quarantine')], isAuthorized: false);

        $this->expectException(NotPermittedException::class);
        $service->merge(new DuplicateGroup('abc', 100, 2, [3, 9]), []);
    }

    #[Test]
    public function skipsACopyWhoseLiveBinaryNoLongerMatchesTheStaleIndex(): void
    {
        $disposed = new \ArrayObject();
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        // Stale index -> never repoint and never dispose a now-distinct asset.
        $repointer->expects(self::never())->method('repoint');

        $group = new DuplicateGroup('abc', 100, 2, [3, 9]);
        $outcome = $this->applyReviewed($this->service($repointer, [$this->strategy('quarantine', $disposed)], liveChecksum: 'changed'), $group);

        self::assertSame([], $disposed->getArrayCopy(), 'a stale copy must never be disposed');
        self::assertSame(DispositionOutcome::Blocked, $outcome->dispositions[0]->outcome);
        self::assertNotNull($outcome->runId);
    }

    #[Test]
    public function returnsAnEmptyOutcomeWhenTheGroupIsSingular(): void
    {
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        $repointer->expects(self::never())->method('repoint');

        $group = new DuplicateGroup('abc', 100, 1, [5]);
        $outcome = $this->applyReviewed($this->service($repointer, [$this->strategy('quarantine')]), $group);

        self::assertSame(0, $outcome->canonicalId);
        self::assertSame([], $outcome->dispositions);
    }

    #[Test]
    public function rejectsAnUnknownStrategyName(): void
    {
        $service = $this->service($this->createMock(DuplicateReferenceRepointer::class), [$this->strategy('quarantine')]);

        $this->expectException(\InvalidArgumentException::class);
        $service->merge(new DuplicateGroup('abc', 100, 2, [1, 2]), [], strategyName: 'nope');
    }

    #[Test]
    public function rejectsACanonicalThatIsNotAMemberOfTheGroup(): void
    {
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        $repointer->expects(self::never())->method('repoint');
        $service = $this->service($repointer, [$this->strategy('quarantine')]);

        $this->expectException(\InvalidArgumentException::class);
        $service->merge(new DuplicateGroup('abc', 100, 2, [3, 9]), [], canonicalId: 999);
    }

    #[Test]
    public function advertisesEveryRegisteredStrategyName(): void
    {
        $service = $this->service(
            $this->createMock(DuplicateReferenceRepointer::class),
            [$this->strategy('quarantine'), $this->strategy('delete'), $this->strategy('isolate')],
        );

        self::assertSame(['quarantine', 'delete', 'isolate'], $service->availableStrategies());
    }

    #[Test]
    public function reportsTheConfiguredDefaultStrategyName(): void
    {
        $service = $this->service($this->createMock(DuplicateReferenceRepointer::class), [$this->strategy('quarantine')]);

        self::assertSame('quarantine', $service->defaultStrategyName());
    }

    #[Test]
    public function rejectsDuplicateStrategyNames(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate duplicate merge strategy alias "quarantine".');

        $this->service(
            $this->createMock(DuplicateReferenceRepointer::class),
            [$this->strategy('quarantine'), $this->strategy('quarantine')],
        );
    }

    #[Test]
    public function failedDispositionDoesNotEmitACommittedMutationEvent(): void
    {
        $strategy = new class () implements DuplicateMergeStrategyInterface {
            public function name(): string
            {
                return 'fail';
            }

            public function repointsReferences(): bool
            {
                return true;
            }

            public function disposeCopy(RepointReport $report, DuplicateMergeContextInterface $context): CopyDisposition
            {
                $copyId = $context->copyId();
                return new CopyDisposition($copyId, DispositionOutcome::LeftError, 'storage failed');
            }
        };
        $events = 0;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(AssetPilotEvents::DUPLICATE_MERGE_COMMITTED, static function () use (&$events): void {
            ++$events;
        });
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        $repointer->method('repoint')->willReturn(new RepointReport(9, 3, 0, []));
        $group = new DuplicateGroup('abc', 100, 2, [3, 9]);
        $outcome = $this->applyReviewed(
            $this->service($repointer, [$strategy], eventDispatcher: $dispatcher),
            $group,
            strategyName: 'fail',
        );

        self::assertSame(DispositionOutcome::LeftError, $outcome->dispositions[0]->outcome);
        self::assertSame(0, $events);
    }

    #[Test]
    public function partialRunRetriesOnlyTheBlockedCopyAndEmitsCommittedEventsOnlyForSuccess(): void
    {
        [$runs] = $this->runStore();
        $blocked = true;
        $events = 0;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(AssetPilotEvents::DUPLICATE_MERGE_COMMITTED, static function () use (&$events): void {
            ++$events;
        });
        $preflight = static function (int $from, int $to) use (&$blocked): RepointPreflight {
            if ($from === 9 && $blocked) {
                return new RepointPreflight(
                    $from,
                    $to,
                    [new ReferrerSnapshot('document', 77, 'doc-v1')],
                    ['document 77 cannot be rewritten'],
                );
            }

            return new RepointPreflight($from, $to, []);
        };
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        $repointer->expects(self::exactly(2))->method('repoint')
            ->willReturnCallback(static fn (int $from, int $to): RepointReport => new RepointReport($from, $to, 1, []));
        $service = $this->service(
            $repointer,
            [$this->strategy('quarantine')],
            runs: $runs,
            eventDispatcher: $dispatcher,
            preflight: $preflight,
        );

        $group = new DuplicateGroup('abc', 100, 3, [3, 7, 9]);
        $partial = $this->applyReviewed($service, $group);

        self::assertSame(OperationRunStatus::Partial, $partial->status);
        self::assertSame([DispositionOutcome::Quarantined, DispositionOutcome::Blocked], array_map(
            static fn (CopyDisposition $disposition): DispositionOutcome => $disposition->outcome,
            $partial->dispositions,
        ));
        self::assertSame(1, $events, 'a blocked copy must not emit a committed mutation event');

        $blocked = false;
        self::assertNotNull($partial->runId);
        $retryId = $runs->retry($partial->runId, ActorContext::system());
        self::assertNotNull($retryId);
        $retried = $service->resume($retryId);

        self::assertSame(OperationRunStatus::Completed, $retried->status);
        self::assertSame(DispositionOutcome::Quarantined, $retried->dispositions[0]->outcome);
        self::assertSame(2, $events);
    }

    #[Test]
    public function resumesAfterDispositionCommittedBeforeItemCompletion(): void
    {
        [$runs] = $this->runStore();
        $recovered = new \ArrayObject();
        $strategy = new class ($recovered) implements ResumableDuplicateMergeStrategyInterface {
            public function __construct(private readonly \ArrayObject $recovered) {}

            public function name(): string
            {
                return 'quarantine';
            }

            public function repointsReferences(): bool
            {
                return true;
            }

            public function disposeCopy(RepointReport $report, DuplicateMergeContextInterface $context): CopyDisposition
            {
                $copyId = $context->copyId();
                throw new \LogicException('Disposition must not repeat after recovery confirms it committed.');
            }

            public function recoverDisposition(int $copyId, RepointReport $report): ?CopyDisposition
            {
                $this->recovered->append($copyId);

                return new CopyDisposition($copyId, DispositionOutcome::Quarantined);
            }
        };
        $identity = static fn (int $id): string => hash('sha256', 'asset:' . $id . ':abc:mutable');
        $runId = $runs->create(DuplicateMergeService::RUN_KIND, ActorContext::system(), [[
            'key' => 'asset:9',
            'type' => 'duplicate_copy',
            'id' => 9,
            'fingerprint' => $identity(9),
            'payload' => [
                'blocked' => [],
                'canonicalFingerprint' => $identity(3),
                'referrers' => [],
            ],
            'state' => [
                'phase' => DuplicateMergePhase::Disposing->value,
                'repointReport' => [
                    'fromAssetId' => 9,
                    'toAssetId' => 3,
                    'repointedObjects' => 1,
                    'blocked' => [],
                ],
            ],
        ]], [
            'assetIds' => [3, 9],
            'canonicalId' => 3,
            'checksum' => 'abc',
            'strategy' => 'quarantine',
            'reviewedFingerprints' => [
                'asset:3' => hash('sha256', 'missing'),
                'asset:9' => hash('sha256', 'missing'),
            ],
        ]);
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        $repointer->expects(self::never())->method('repoint');
        $events = 0;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(AssetPilotEvents::DUPLICATE_MERGE_COMMITTED, static function () use (&$events): void {
            ++$events;
        });
        $service = $this->service($repointer, [$strategy], runs: $runs, eventDispatcher: $dispatcher);

        $outcome = $service->resume($runId);

        self::assertSame(OperationRunStatus::Completed, $outcome->status);
        self::assertSame([9], $recovered->getArrayCopy());
        self::assertSame(1, $events);
        $stored = $runs->get($runId, ActorContext::system());
        self::assertNotNull($stored);
        self::assertSame(DuplicateMergePhase::Committed->value, $stored['items'][0]['state_payload']['phase']);
        self::assertSame(1, (int) $stored['items'][0]['attempts']);
    }

    #[Test]
    public function finalizesAPersistedCommittedDispositionWithoutRepeatingSideEffects(): void
    {
        [$runs] = $this->runStore();
        $strategy = new class () implements DuplicateMergeStrategyInterface {
            public function name(): string
            {
                return 'quarantine';
            }

            public function repointsReferences(): bool
            {
                return true;
            }

            public function disposeCopy(RepointReport $report, DuplicateMergeContextInterface $context): CopyDisposition
            {
                $copyId = $context->copyId();
                throw new \LogicException('A committed disposition must never run again.');
            }
        };
        $identity = static fn (int $id): string => hash('sha256', 'asset:' . $id . ':abc:mutable');
        $runId = $runs->create(DuplicateMergeService::RUN_KIND, ActorContext::system(), [[
            'key' => 'asset:9',
            'type' => 'duplicate_copy',
            'id' => 9,
            'fingerprint' => $identity(9),
            'payload' => [
                'blocked' => [],
                'canonicalFingerprint' => $identity(3),
                'referrers' => [],
            ],
            'state' => [
                'phase' => DuplicateMergePhase::Committed->value,
                'repointReport' => [
                    'fromAssetId' => 9,
                    'toAssetId' => 3,
                    'repointedObjects' => 1,
                    'blocked' => [],
                ],
                'disposition' => [
                    'copyId' => 9,
                    'outcome' => DispositionOutcome::Quarantined->value,
                    'reason' => '',
                ],
            ],
        ]], [
            'assetIds' => [3, 9],
            'canonicalId' => 3,
            'checksum' => 'abc',
            'strategy' => 'quarantine',
            'reviewedFingerprints' => [
                'asset:3' => hash('sha256', 'missing'),
                'asset:9' => hash('sha256', 'missing'),
            ],
        ]);
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        $repointer->expects(self::never())->method('repoint');
        $service = $this->service($repointer, [$strategy], runs: $runs);

        $outcome = $service->resume($runId);

        self::assertSame(OperationRunStatus::Completed, $outcome->status);
        self::assertSame(DispositionOutcome::Quarantined, $outcome->dispositions[0]->outcome);
    }

    #[Test]
    public function resumesAnInterruptedRepointWithoutRewritingAlreadyRemovedReferrers(): void
    {
        [$runs] = $this->runStore();
        $identity = static fn (int $id): string => hash('sha256', 'asset:' . $id . ':abc:mutable');
        $runId = $runs->create(DuplicateMergeService::RUN_KIND, ActorContext::system(), [[
            'key' => 'asset:9',
            'type' => 'duplicate_copy',
            'id' => 9,
            'fingerprint' => $identity(9),
            'payload' => [
                'blocked' => [],
                'canonicalFingerprint' => $identity(3),
                'referrers' => [['type' => 'object', 'id' => 42, 'fingerprint' => 'before-repoint']],
            ],
            'state' => [
                'phase' => DuplicateMergePhase::Repointing->value,
                'repointReport' => null,
            ],
        ]], [
            'assetIds' => [3, 9],
            'canonicalId' => 3,
            'checksum' => 'abc',
            'strategy' => 'quarantine',
            'reviewedFingerprints' => [
                'asset:3' => hash('sha256', 'missing'),
                'asset:9' => hash('sha256', 'missing'),
            ],
        ]);
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        $repointer->expects(self::once())->method('repoint')
            ->with(9, 3)
            ->willReturn(new RepointReport(9, 3, 0, []));
        $service = $this->service($repointer, [$this->strategy('quarantine')], runs: $runs);

        $outcome = $service->resume($runId);

        self::assertSame(OperationRunStatus::Completed, $outcome->status);
        self::assertSame(DispositionOutcome::Quarantined, $outcome->dispositions[0]->outcome);
    }

    #[Test]
    public function skipsTheWholeMergeWhenAnotherWorkerHoldsAGroupAssetLock(): void
    {
        $store = new InMemoryStore();
        $owner = new LoopGuard(new ArrayAdapter(), new LockFactory($store));
        $worker = new LoopGuard(new ArrayAdapter(), new LockFactory($store));
        self::assertTrue($owner->acquireAsset(9));
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        $repointer->expects(self::never())->method('repoint');

        $group = new DuplicateGroup('abc', 100, 2, [3, 9]);
        $outcome = $this->applyReviewed($this->service(
            $repointer,
            [$this->strategy('quarantine')],
            loopGuard: $worker,
        ), $group);

        self::assertSame(DispositionOutcome::Blocked, $outcome->dispositions[0]->outcome);
        self::assertStringContainsString('pending', (string) $outcome->dispositions[0]->reason);
        self::assertSame(OperationRunStatus::Queued, $outcome->status);
        self::assertNotNull($outcome->runId);
    }
}
