<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\HealOutcome;
use Oronts\AssetPilotBundle\Enum\IntegrityStatus;
use Oronts\AssetPilotBundle\Enum\NotificationSeverity;
use Oronts\AssetPilotBundle\Enum\UndoHealOutcome;
use Oronts\AssetPilotBundle\Enum\UndoHealReason;
use Oronts\AssetPilotBundle\Event\AssetHealEvent;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Integrity\CompositeIntegrityChecker;
use Oronts\AssetPilotBundle\Integrity\IntegrityCheckerInterface;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\IntegrityResult;
use Oronts\AssetPilotBundle\Notification\Notification;
use Oronts\AssetPilotBundle\Notification\NotificationDispatcherInterface;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\AssetProtection;
use Oronts\AssetPilotBundle\Service\IntegrityHealFingerprintService;
use Oronts\AssetPilotBundle\Service\IntegrityHealLog;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\LoopGuardedAssetSaver;
use Oronts\AssetPilotBundle\Service\QuarantineService;
use Oronts\AssetPilotBundle\Service\ReviewedAssetLockCoordinator;
use Oronts\AssetPilotBundle\Service\VersionRollbackHealer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\Version;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(VersionRollbackHealer::class)]
class VersionRollbackHealerTest extends TestCase
{
    /** @param array<string, IntegrityStatus> $binaryVerdicts */
    private function checker(IntegrityStatus $live, array $binaryVerdicts = []): CompositeIntegrityChecker
    {
        $stub = new class ($live, $binaryVerdicts) implements IntegrityCheckerInterface {
            /** @param array<string, IntegrityStatus> $binaryVerdicts */
            public function __construct(private readonly IntegrityStatus $live, private readonly array $binaryVerdicts) {}

            public function priority(): int
            {
                return 10;
            }

            public function supports(Asset $asset): bool
            {
                return true;
            }

            public function check(Asset $asset): IntegrityResult
            {
                return new IntegrityResult($this->live, 'stub');
            }

            public function checkBinary(string $binary, string $extension): IntegrityResult
            {
                return new IntegrityResult($this->binaryVerdicts[$binary] ?? IntegrityStatus::Broken, 'stub');
            }
        };

        return new CompositeIntegrityChecker([$stub]);
    }

    private function version(int $id, int $cid = 7): Version
    {
        // Version is final (cannot be mocked) and its constructor needs the Pimcore container; bypass
        // it and set only id/cid, which is all the healer reads off a Version in these tests.
        $version = (new \ReflectionClass(Version::class))->newInstanceWithoutConstructor();
        $version->setId($id);
        $version->setCid($cid);

        return $version;
    }

    private function asset(int $id = 7): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn($id);
        $asset->method('getFilename')->willReturn('photo.jpg');

        return $asset;
    }

    /**
     * @param list<Version>          $versions          newest-first
     * @param array<int, ?string>    $binaryByVersionId
     * @param \ArrayObject<int, int> $restored          appended with each restored version id
     * @param array<int, ?Asset>     $assetsById        for undo's loadAsset() seam
     * @param array<int, ?Version>   $versionsById      for undo's loadVersion() seam
     */
    private function healer(
        CompositeIntegrityChecker $checker,
        IntegrityHealLog $healLog,
        \ArrayObject $restored,
        array $versions = [],
        array $binaryByVersionId = [],
        bool $cancelPreHeal = false,
        ?QuarantineService $quarantine = null,
        string $onUnrecoverable = 'report',
        array $assetsById = [],
        array $versionsById = [],
        ?NotificationDispatcherInterface $notifier = null,
        string $liveBinary = '',
        bool $throwOnRestore = false,
        ?LoopGuard $loopGuard = null,
        ?ElementAuthorization $authorization = null,
        ?EventDispatcher $dispatcher = null,
        array $excludeFolders = [],
        ?IntegrityHealFingerprintService $healFingerprints = null,
    ): VersionRollbackHealer {
        $dispatcher ??= new EventDispatcher();
        if ($cancelPreHeal) {
            $dispatcher->addListener('oronts_asset_pilot.integrity_pre_heal', static fn (AssetHealEvent $e) => $e->cancel());
        }

        $loopGuard ??= new LoopGuard(new ArrayAdapter(), new LockFactory(new InMemoryStore()));
        $authorization ??= $this->authorization();

        return new class ($checker, $loopGuard, $healLog, $dispatcher, $authorization, $restored, $versions, $binaryByVersionId, $quarantine, $onUnrecoverable, $assetsById, $versionsById, $notifier, $liveBinary, $throwOnRestore, $excludeFolders, $healFingerprints) extends VersionRollbackHealer {
            /**
             * @param \ArrayObject<int, int> $restored
             * @param list<Version>          $versions
             * @param array<int, ?string>    $binaryByVersionId
             * @param array<int, ?Asset>     $assetsById
             * @param array<int, ?Version>   $versionsById
             */
            public function __construct(
                CompositeIntegrityChecker $checker,
                LoopGuard $loopGuard,
                IntegrityHealLog $healLog,
                EventDispatcher $dispatcher,
                ElementAuthorization $authorization,
                private readonly \ArrayObject $restored,
                private readonly array $versions,
                private readonly array $binaryByVersionId,
                ?QuarantineService $quarantine,
                string $onUnrecoverable,
                private readonly array $assetsById,
                private readonly array $versionsById,
                ?NotificationDispatcherInterface $notifier,
                private readonly string $liveBytes,
                private readonly bool $throwOnRestore,
                array $excludeFolders,
                ?IntegrityHealFingerprintService $healFingerprints,
            ) {
                parent::__construct(
                    $checker,
                    $loopGuard,
                    new ReviewedAssetLockCoordinator($loopGuard),
                    $dispatcher,
                    $healLog,
                    new NullLogger(),
                    $authorization,
                    new LoopGuardedAssetSaver($loopGuard),
                    $quarantine,
                    $onUnrecoverable,
                    $notifier,
                    $excludeFolders,
                    AssetProtection::DEFAULT_LOCK_PROPERTY,
                    $healFingerprints,
                );
            }

            protected function newestFirstVersions(Asset $asset): array
            {
                return $this->versions;
            }

            protected function versionBinary(Version $version): ?string
            {
                return $this->binaryByVersionId[(int) $version->getId()] ?? null;
            }

            protected function restore(Asset $asset, Version $version): void
            {
                if ($this->throwOnRestore) {
                    throw new \RuntimeException('storage write failed');
                }
                $this->restored->append((int) $version->getId());
            }

            protected function loadAsset(int $assetId): ?Asset
            {
                return $this->assetsById[$assetId] ?? null;
            }

            protected function reloadAsset(Asset $asset): ?Asset
            {
                $assetId = (int) $asset->getId();

                return array_key_exists($assetId, $this->assetsById) ? $this->assetsById[$assetId] : $asset;
            }

            protected function loadVersion(int $versionId): ?Version
            {
                return $this->versionsById[$versionId] ?? null;
            }

            protected function liveBinary(Asset $asset): ?string
            {
                return $this->liveBytes;
            }
        };
    }

    #[Test]
    public function healsToTheNewestRenderableVersion(): void
    {
        $healLog = $this->createMock(IntegrityHealLog::class);
        // Two-phase audit: a pending row is opened before the restore and committed after.
        $healLog->expects(self::once())->method('beginHeal')->with(7, 3, 2, 'stub')->willReturn(55);
        $healLog->expects(self::once())->method('commitHeal')->with(55)->willReturn(true);

        $restored = new \ArrayObject();
        $result = $this->healer(
            $this->checker(IntegrityStatus::Broken, ['bad' => IntegrityStatus::Broken, 'good' => IntegrityStatus::Renderable]),
            $healLog,
            $restored,
            [$this->version(3), $this->version(2), $this->version(1)],
            [3 => 'bad', 2 => 'good', 1 => 'good'],
        )->heal($this->asset());

        self::assertSame(HealOutcome::Healed, $result->outcome);
        self::assertSame(2, $result->toVersion);
        self::assertSame([2], $restored->getArrayCopy());
        self::assertNull($result->reason, 'a cleanly committed heal carries no warning');
    }

    #[Test]
    public function postHealObserverFailureDoesNotChangeTheCommittedHealAndLaterObserversRun(): void
    {
        $dispatcher = new EventDispatcher();
        $laterObserverCalled = false;
        $dispatcher->addListener(AssetPilotEvents::INTEGRITY_POST_HEAL, static fn (): never => throw new \RuntimeException('Observer failed.'), 10);
        $dispatcher->addListener(AssetPilotEvents::INTEGRITY_POST_HEAL, static function () use (&$laterObserverCalled): void {
            $laterObserverCalled = true;
        });

        $healLog = $this->createMock(IntegrityHealLog::class);
        $healLog->method('beginHeal')->willReturn(55);
        $healLog->method('commitHeal')->willReturn(true);
        $restored = new \ArrayObject();

        $result = $this->healer(
            $this->checker(IntegrityStatus::Broken, ['good' => IntegrityStatus::Renderable]),
            $healLog,
            $restored,
            [$this->version(2)],
            [2 => 'good'],
            dispatcher: $dispatcher,
        )->heal($this->asset());

        self::assertTrue($laterObserverCalled);
        self::assertSame(HealOutcome::Healed, $result->outcome);
        self::assertSame([2], $restored->getArrayCopy());
        self::assertNull($result->reason);
        self::assertSame(['Integrity post-heal observer delivery failed.'], $result->observerWarnings);
    }

    #[Test]
    public function reportsHealedButFlagsUndoUnavailableWhenTheCommitDoesNotPersist(): void
    {
        $healLog = $this->createMock(IntegrityHealLog::class);
        $healLog->method('beginHeal')->willReturn(55);
        // The pending row could not be promoted to healed: the binary is fixed but undo is at risk.
        $healLog->method('commitHeal')->with(55)->willReturn(false);

        $restored = new \ArrayObject();
        $result = $this->healer(
            $this->checker(IntegrityStatus::Broken, ['good' => IntegrityStatus::Renderable]),
            $healLog,
            $restored,
            [$this->version(2)],
            [2 => 'good'],
        )->heal($this->asset());

        self::assertSame(HealOutcome::Healed, $result->outcome, 'the binary was restored, so the outcome stays Healed');
        self::assertSame([2], $restored->getArrayCopy());
        self::assertNotNull($result->reason, 'a non-finalised audit row must be surfaced, not hidden');
    }

    #[Test]
    public function abortsTheHealWhenTheAuditRowCannotBeOpened(): void
    {
        $healLog = $this->createMock(IntegrityHealLog::class);
        $healLog->method('beginHeal')->willReturn(null);
        $healLog->expects(self::never())->method('commitHeal');

        $restored = new \ArrayObject();
        $result = $this->healer(
            $this->checker(IntegrityStatus::Broken, ['good' => IntegrityStatus::Renderable]),
            $healLog,
            $restored,
            [$this->version(2)],
            [2 => 'good'],
        )->heal($this->asset());

        self::assertSame(HealOutcome::Skipped, $result->outcome);
        self::assertSame([], $restored->getArrayCopy(), 'the destructive restore must not run without an audit row');
    }

    #[Test]
    public function marksTheRowFailedAndDoesNotReportHealedWhenRestoreThrows(): void
    {
        $healLog = $this->createMock(IntegrityHealLog::class);
        $healLog->method('beginHeal')->willReturn(77);
        $healLog->expects(self::once())->method('failHeal')->with(77);
        $healLog->expects(self::never())->method('commitHeal');

        $result = $this->healer(
            $this->checker(IntegrityStatus::Broken, ['good' => IntegrityStatus::Renderable]),
            $healLog,
            new \ArrayObject(),
            [$this->version(2)],
            [2 => 'good'],
            throwOnRestore: true,
        )->heal($this->asset());

        self::assertSame(HealOutcome::Skipped, $result->outcome);
    }

    #[Test]
    public function unrecoverableWhenNoVersionRenders(): void
    {
        $healLog = $this->createMock(IntegrityHealLog::class);
        $healLog->expects(self::once())->method('record')
            ->with(7, null, null, 'stub', IntegrityHealLog::STATUS_UNRECOVERABLE);

        $restored = new \ArrayObject();
        $result = $this->healer(
            $this->checker(IntegrityStatus::Broken, ['bad' => IntegrityStatus::Broken]),
            $healLog,
            $restored,
            [$this->version(2), $this->version(1)],
            [2 => 'bad', 1 => 'bad'],
        )->heal($this->asset());

        self::assertSame(HealOutcome::Unrecoverable, $result->outcome);
        self::assertSame([], $restored->getArrayCopy());
    }

    #[Test]
    public function unrecoverableDispatchesANotification(): void
    {
        $notifier = $this->createMock(NotificationDispatcherInterface::class);
        $notifier->expects(self::once())->method('dispatch')->with(self::callback(static fn (Notification $notification): bool =>
            $notification->kind === 'integrity.unrecoverable'
            && $notification->severity === NotificationSeverity::Critical
            && $notification->context === ['assetId' => 7, 'quarantined' => false],
        ));

        $this->healer(
            $this->checker(IntegrityStatus::Broken, ['bad' => IntegrityStatus::Broken]),
            $this->createMock(IntegrityHealLog::class),
            new \ArrayObject(),
            [$this->version(1)],
            [1 => 'bad'],
            notifier: $notifier,
        )->heal($this->asset());
    }

    #[Test]
    public function repeatedUnrecoverableDoesNotReNotify(): void
    {
        $healLog = $this->createMock(IntegrityHealLog::class);
        $healLog->method('latestStatus')->willReturn(IntegrityHealLog::STATUS_UNRECOVERABLE);

        $notifier = $this->createMock(NotificationDispatcherInterface::class);
        $notifier->expects(self::never())->method('dispatch');

        $this->healer(
            $this->checker(IntegrityStatus::Broken, ['bad' => IntegrityStatus::Broken]),
            $healLog,
            new \ArrayObject(),
            [$this->version(1)],
            [1 => 'bad'],
            notifier: $notifier,
        )->heal($this->asset());
    }

    #[Test]
    public function alreadyRenderableDoesNothing(): void
    {
        $healLog = $this->createMock(IntegrityHealLog::class);
        $healLog->expects(self::never())->method('record');

        $result = $this->healer($this->checker(IntegrityStatus::Renderable), $healLog, new \ArrayObject())->heal($this->asset());

        self::assertSame(HealOutcome::AlreadyRenderable, $result->outcome);
    }

    #[Test]
    public function unverifiableIsNeverHealed(): void
    {
        $healLog = $this->createMock(IntegrityHealLog::class);
        $healLog->expects(self::never())->method('record');

        $result = $this->healer($this->checker(IntegrityStatus::Unverifiable), $healLog, new \ArrayObject())->heal($this->asset());

        self::assertSame(HealOutcome::Unverifiable, $result->outcome);
    }

    #[Test]
    public function dryRunPicksAVersionButWritesNothing(): void
    {
        $healLog = $this->createMock(IntegrityHealLog::class);
        $healLog->expects(self::never())->method('record');

        $restored = new \ArrayObject();
        $result = $this->healer(
            $this->checker(IntegrityStatus::Broken, ['good' => IntegrityStatus::Renderable]),
            $healLog,
            $restored,
            [$this->version(2)],
            [2 => 'good'],
        )->heal($this->asset(), dryRun: true);

        self::assertSame(HealOutcome::Healed, $result->outcome);
        self::assertTrue($result->dryRun);
        self::assertSame(2, $result->toVersion);
        self::assertSame([], $restored->getArrayCopy(), 'dry run must not restore');
    }

    #[Test]
    public function previewByIdDoesNotDispatchHealEventsOrWriteAuditState(): void
    {
        $events = 0;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(AssetPilotEvents::INTEGRITY_PRE_HEAL, static function () use (&$events): void {
            ++$events;
        });
        $dispatcher->addListener(AssetPilotEvents::INTEGRITY_POST_HEAL, static function () use (&$events): void {
            ++$events;
        });
        $healLog = $this->createMock(IntegrityHealLog::class);
        $healLog->expects(self::never())->method('beginHeal');
        $healLog->expects(self::never())->method('record');
        $asset = $this->asset();
        $restored = new \ArrayObject();

        $result = $this->healer(
            $this->checker(IntegrityStatus::Broken, ['good' => IntegrityStatus::Renderable]),
            $healLog,
            $restored,
            [$this->version(2)],
            [2 => 'good'],
            assetsById: [7 => $asset],
            dispatcher: $dispatcher,
        )->previewById(7);

        self::assertSame(HealOutcome::Healed, $result->outcome);
        self::assertTrue($result->dryRun);
        self::assertSame(0, $events);
        self::assertSame([], $restored->getArrayCopy());
    }

    #[Test]
    public function staleLaterBatchTargetPreventsEveryEarlierMutationAndReleasesLocksInReverse(): void
    {
        $lockCalls = [];
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturnCallback(static function (int $assetId) use (&$lockCalls): bool {
            $lockCalls[] = 'acquire:' . $assetId;

            return true;
        });
        $loopGuard->method('releaseAsset')->willReturnCallback(static function (int $assetId) use (&$lockCalls): void {
            $lockCalls[] = 'release:' . $assetId;
        });
        $fingerprints = $this->createMock(IntegrityHealFingerprintService::class);
        $fingerprints->expects(self::exactly(2))->method('assertUnchanged')->willReturnCallback(
            static function (int $assetId): void {
                if ($assetId === 8) {
                    throw new StaleApplyPlanException('later target changed');
                }
            },
        );
        $healLog = $this->createMock(IntegrityHealLog::class);
        $healLog->expects(self::never())->method('beginHeal');
        $healLog->expects(self::never())->method('record');
        $restored = new \ArrayObject();

        try {
            $this->healer(
                $this->checker(IntegrityStatus::Broken, ['good' => IntegrityStatus::Renderable]),
                $healLog,
                $restored,
                [$this->version(2)],
                [2 => 'good'],
                assetsById: [7 => $this->asset(7), 8 => $this->asset(8)],
                loopGuard: $loopGuard,
                healFingerprints: $fingerprints,
            )->healPlannedBatch([8, 7], ['asset:7' => 'first', 'asset:8' => 'second']);
            self::fail('Expected the stale later target to abort the batch.');
        } catch (StaleApplyPlanException) {
        }

        self::assertSame([], $restored->getArrayCopy());
        self::assertSame(['acquire:7', 'acquire:8', 'release:8', 'release:7'], $lockCalls);
    }

    #[Test]
    public function plannedHealRevalidatesTheForceReloadedAssetImmediatelyBeforeRestore(): void
    {
        $assertions = 0;
        $fingerprints = $this->createMock(IntegrityHealFingerprintService::class);
        $fingerprints->expects(self::exactly(3))->method('assertUnchanged')->willReturnCallback(
            static function () use (&$assertions): void {
                ++$assertions;
                if ($assertions === 3) {
                    throw new StaleApplyPlanException('asset changed before restore');
                }
            },
        );
        $healLog = $this->createMock(IntegrityHealLog::class);
        $healLog->expects(self::once())->method('beginHeal')->willReturn(55);
        $healLog->expects(self::once())->method('failHeal')->with(55);
        $healLog->expects(self::never())->method('commitHeal');
        $restored = new \ArrayObject();
        $asset = $this->asset();

        try {
            $this->healer(
                $this->checker(IntegrityStatus::Broken, ['good' => IntegrityStatus::Renderable]),
                $healLog,
                $restored,
                [$this->version(2)],
                [2 => 'good'],
                assetsById: [7 => $asset],
                healFingerprints: $fingerprints,
            )->healPlannedBatch([7], ['asset:7' => 'expected']);
            self::fail('Expected the final force-reloaded fingerprint check to abort the restore.');
        } catch (StaleApplyPlanException) {
        }

        self::assertSame([], $restored->getArrayCopy());
    }

    #[Test]
    public function aCancelledPreHealSkipsTheWrite(): void
    {
        $healLog = $this->createMock(IntegrityHealLog::class);
        $healLog->expects(self::never())->method('record');

        $restored = new \ArrayObject();
        $result = $this->healer(
            $this->checker(IntegrityStatus::Broken, ['good' => IntegrityStatus::Renderable]),
            $healLog,
            $restored,
            [$this->version(2)],
            [2 => 'good'],
            cancelPreHeal: true,
        )->heal($this->asset());

        self::assertSame(HealOutcome::Skipped, $result->outcome);
        self::assertSame([], $restored->getArrayCopy());
    }

    #[Test]
    public function healSkipsWhenAnotherWorkerHoldsTheAssetLock(): void
    {
        $store = new InMemoryStore();
        $owner = new LoopGuard(new ArrayAdapter(), new LockFactory($store));
        $worker = new LoopGuard(new ArrayAdapter(), new LockFactory($store));
        self::assertTrue($owner->acquireAsset(7));

        $restored = new \ArrayObject();
        $result = $this->healer(
            $this->checker(IntegrityStatus::Broken, ['good' => IntegrityStatus::Renderable]),
            $this->createMock(IntegrityHealLog::class),
            $restored,
            [$this->version(2)],
            [2 => 'good'],
            loopGuard: $worker,
        )->heal($this->asset());

        self::assertSame(HealOutcome::Skipped, $result->outcome);
        self::assertSame([], $restored->getArrayCopy());
    }

    #[Test]
    public function healSkipsWhenTheScopedActorCannotPublishTheAsset(): void
    {
        $healLog = $this->createMock(IntegrityHealLog::class);
        $healLog->expects(self::never())->method('beginHeal');

        $result = $this->healer(
            $this->checker(IntegrityStatus::Broken, ['good' => IntegrityStatus::Renderable]),
            $healLog,
            new \ArrayObject(),
            [$this->version(2)],
            [2 => 'good'],
            authorization: $this->authorization(false),
        )->heal($this->asset());

        self::assertSame(HealOutcome::Skipped, $result->outcome);
        self::assertSame('Not permitted to heal this asset.', $result->reason);
    }

    #[Test]
    public function dryRunDoesNotClaimAHealWhenTheActorCannotApplyIt(): void
    {
        $asset = $this->asset();
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (Asset $candidate, string $permission): bool => $candidate === $asset && $permission === 'view',
        );

        $result = $this->healer(
            $this->checker(IntegrityStatus::Broken, ['good' => IntegrityStatus::Renderable]),
            $this->createMock(IntegrityHealLog::class),
            new \ArrayObject(),
            [$this->version(2)],
            [2 => 'good'],
            authorization: $authorization,
        )->heal($asset, dryRun: true);

        self::assertSame(HealOutcome::Skipped, $result->outcome);
        self::assertSame('Not permitted to heal this asset.', $result->reason);
        self::assertTrue($result->dryRun);
    }

    #[Test]
    public function dryRunChecksTheReloadedAsset(): void
    {
        $stale = $this->asset();
        $current = $this->asset();
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (Asset $candidate): bool => $candidate === $current,
        );

        $result = $this->healer(
            $this->checker(IntegrityStatus::Broken, ['good' => IntegrityStatus::Renderable]),
            $this->createMock(IntegrityHealLog::class),
            new \ArrayObject(),
            [$this->version(2)],
            [2 => 'good'],
            assetsById: [7 => $current],
            authorization: $authorization,
        )->heal($stale, dryRun: true);

        self::assertSame(HealOutcome::Healed, $result->outcome);
        self::assertTrue($result->dryRun);
    }

    #[Test]
    public function dryRunSkipsAnAssetProtectedByTheLockProperty(): void
    {
        $asset = $this->asset();
        $asset->method('hasProperty')->with(AssetProtection::DEFAULT_LOCK_PROPERTY)->willReturn(true);
        $asset->method('getProperty')->with(AssetProtection::DEFAULT_LOCK_PROPERTY)->willReturn(true);

        $result = $this->healer(
            $this->checker(IntegrityStatus::Broken),
            $this->createMock(IntegrityHealLog::class),
            new \ArrayObject(),
        )->heal($asset, dryRun: true);

        self::assertSame(HealOutcome::Skipped, $result->outcome);
        self::assertSame('Asset is locked.', $result->reason);
        self::assertTrue($result->dryRun);
    }

    #[Test]
    public function dryRunSkipsAnExcludedAsset(): void
    {
        $asset = $this->asset();
        $asset->method('getRealFullPath')->willReturn('/protected/photo.jpg');

        $result = $this->healer(
            $this->checker(IntegrityStatus::Broken),
            $this->createMock(IntegrityHealLog::class),
            new \ArrayObject(),
            excludeFolders: ['/protected'],
        )->heal($asset, dryRun: true);

        self::assertSame(HealOutcome::Skipped, $result->outcome);
        self::assertSame('Asset is in an excluded folder.', $result->reason);
        self::assertTrue($result->dryRun);
    }

    #[Test]
    public function dryRunSkipsWhenAnotherWorkerOwnsTheAssetLock(): void
    {
        $store = new InMemoryStore();
        $owner = new LoopGuard(new ArrayAdapter(), new LockFactory($store));
        $worker = new LoopGuard(new ArrayAdapter(), new LockFactory($store));
        self::assertTrue($owner->acquireAsset(7));

        $result = $this->healer(
            $this->checker(IntegrityStatus::Broken),
            $this->createMock(IntegrityHealLog::class),
            new \ArrayObject(),
            loopGuard: $worker,
        )->heal($this->asset(), dryRun: true);

        self::assertSame(HealOutcome::Skipped, $result->outcome);
        self::assertSame('Asset is being processed by another job.', $result->reason);
        self::assertTrue($result->dryRun);
    }

    #[Test]
    public function unrecoverableRoutesToQuarantineWhenConfigured(): void
    {
        $quarantine = $this->createMock(QuarantineService::class);
        $quarantine->expects(self::once())->method('quarantine')->with([7])->willReturn(['quarantined' => 1, 'failed' => 0, 'errors' => []]);

        $this->healer(
            $this->checker(IntegrityStatus::Broken, ['bad' => IntegrityStatus::Broken]),
            $this->createMock(IntegrityHealLog::class),
            new \ArrayObject(),
            [$this->version(1)],
            [1 => 'bad'],
            quarantine: $quarantine,
            onUnrecoverable: 'quarantine',
        )->heal($this->asset());
    }

    #[Test]
    public function undoRestoresThePreHealVersionAndMarksItUndone(): void
    {
        $healLog = $this->createMock(IntegrityHealLog::class);
        $healLog->method('findUndoable')->with(7)->willReturn(['id' => 5, 'from_version' => 3, 'to_version' => 2]);
        $healLog->expects(self::once())->method('markUndone')->with(5)->willReturn(true);

        $restored = new \ArrayObject();
        $undone = $this->healer(
            $this->checker(IntegrityStatus::Broken),
            $healLog,
            $restored,
            binaryByVersionId: [2 => 'healed-bytes'],
            assetsById: [7 => $this->asset()],
            versionsById: [3 => $this->version(3), 2 => $this->version(2)],
            liveBinary: 'healed-bytes',
        )->undo(7);

        self::assertTrue($undone);
        self::assertSame([3], $restored->getArrayCopy());
    }

    #[Test]
    public function eligibilityProbeDoesNotTakeTheMutationLockOrHashBinaries(): void
    {
        $store = new InMemoryStore();
        $owner = new LoopGuard(new ArrayAdapter(), new LockFactory($store));
        $probe = new LoopGuard(new ArrayAdapter(), new LockFactory($store));
        self::assertTrue($owner->acquireAsset(7));

        $healLog = $this->createMock(IntegrityHealLog::class);
        $healLog->expects(self::never())->method('findUndoable');
        $result = $this->healer(
            $this->checker(IntegrityStatus::Broken),
            $healLog,
            new \ArrayObject(),
            binaryByVersionId: [2 => 'healed-bytes'],
            assetsById: [7 => $this->asset()],
            versionsById: [3 => $this->version(3), 2 => $this->version(2)],
            liveBinary: 'different-live-bytes',
            loopGuard: $probe,
        )->assessUndoEligibility(7, 3);

        self::assertSame(UndoHealOutcome::WouldReverse, $result->outcome);
        self::assertTrue($result->dryRun);
    }

    #[Test]
    public function eligibilityProbeReportsADeletedPreHealVersion(): void
    {
        $result = $this->healer(
            $this->checker(IntegrityStatus::Broken),
            $this->createMock(IntegrityHealLog::class),
            new \ArrayObject(),
            assetsById: [7 => $this->asset()],
        )->assessUndoEligibility(7, 3);

        self::assertSame(UndoHealReason::VersionMissing, $result->reasonCode);
    }

    #[Test]
    public function undoPreviewRunsTheFeasibilityChecksWithoutRestoring(): void
    {
        $healLog = $this->createMock(IntegrityHealLog::class);
        $healLog->method('findUndoable')->with(7)->willReturn(['id' => 5, 'from_version' => 3, 'to_version' => 2]);
        $healLog->expects(self::never())->method('markUndone');

        $restored = new \ArrayObject();
        $result = $this->healer(
            $this->checker(IntegrityStatus::Broken),
            $healLog,
            $restored,
            binaryByVersionId: [2 => 'healed-bytes'],
            assetsById: [7 => $this->asset()],
            versionsById: [3 => $this->version(3), 2 => $this->version(2)],
            liveBinary: 'healed-bytes',
        )->undoDetailed(7, dryRun: true);

        self::assertTrue($result->isSuccessful());
        self::assertSame(UndoHealOutcome::WouldReverse, $result->outcome);
        self::assertTrue($result->dryRun);
        self::assertSame([], $restored->getArrayCopy());
    }

    #[Test]
    public function undoReportsWhenTheAuditRowCouldNotBeFinalised(): void
    {
        $healLog = $this->createMock(IntegrityHealLog::class);
        $healLog->method('findUndoable')->willReturn(['id' => 5, 'from_version' => 3, 'to_version' => 2]);
        $healLog->method('markUndone')->with(5)->willReturn(false);

        $restored = new \ArrayObject();
        $result = $this->healer(
            $this->checker(IntegrityStatus::Broken),
            $healLog,
            $restored,
            binaryByVersionId: [2 => 'healed-bytes'],
            assetsById: [7 => $this->asset()],
            versionsById: [3 => $this->version(3), 2 => $this->version(2)],
            liveBinary: 'healed-bytes',
        )->undoDetailed(7);

        self::assertFalse($result->isSuccessful());
        self::assertSame(UndoHealOutcome::Failed, $result->outcome);
        self::assertSame(UndoHealReason::LogUpdateFailed, $result->reasonCode);
        self::assertSame('The asset was restored, but the integrity log could not be marked undone.', $result->reason);
        self::assertSame([3], $restored->getArrayCopy());
    }

    #[Test]
    public function undoIsRefusedWhenTheLiveBinaryDivergedSinceTheHeal(): void
    {
        $healLog = $this->createMock(IntegrityHealLog::class);
        $healLog->method('findUndoable')->with(7)->willReturn(['id' => 5, 'from_version' => 3, 'to_version' => 2]);
        // The asset was changed (re-uploaded/re-healed) since the heal, so rolling back to the
        // pre-heal version would clobber that newer content: undo must abort, not restore.
        $healLog->expects(self::never())->method('markUndone');

        $restored = new \ArrayObject();
        $undone = $this->healer(
            $this->checker(IntegrityStatus::Broken),
            $healLog,
            $restored,
            binaryByVersionId: [2 => 'healed-bytes'],
            assetsById: [7 => $this->asset()],
            versionsById: [3 => $this->version(3), 2 => $this->version(2)],
            liveBinary: 'user-reuploaded-different-bytes',
        )->undo(7);

        self::assertFalse($undone);
        self::assertSame([], $restored->getArrayCopy(), 'a diverged asset must not be rolled back');
    }

    #[Test]
    public function undoReturnsFalseWhenThereIsNothingToUndo(): void
    {
        $healLog = $this->createMock(IntegrityHealLog::class);
        $healLog->method('findUndoable')->willReturn(null);
        $healLog->expects(self::never())->method('markUndone');

        $restored = new \ArrayObject();
        self::assertFalse($this->healer($this->checker(IntegrityStatus::Broken), $healLog, $restored)->undo(7));
        self::assertSame([], $restored->getArrayCopy());
    }

    #[Test]
    public function undoDoesNotReadAuditStateWhenAnotherWorkerHoldsTheAssetLock(): void
    {
        $store = new InMemoryStore();
        $owner = new LoopGuard(new ArrayAdapter(), new LockFactory($store));
        $worker = new LoopGuard(new ArrayAdapter(), new LockFactory($store));
        self::assertTrue($owner->acquireAsset(7));

        $healLog = $this->createMock(IntegrityHealLog::class);
        $healLog->expects(self::never())->method('findUndoable');

        self::assertFalse($this->healer(
            $this->checker(IntegrityStatus::Broken),
            $healLog,
            new \ArrayObject(),
            loopGuard: $worker,
        )->undo(7));
    }

    #[Test]
    public function restoreThrowsWhenTheVersionBelongsToAnotherAsset(): void
    {
        $healer = $this->restoreHealer();

        $this->expectException(\RuntimeException::class);
        $healer->callRestore($this->asset(7), $this->version(2, cid: 99));
    }

    #[Test]
    public function restoreWrapsTheSaveInTheLoopGuardWindow(): void
    {
        $loopGuard = new LoopGuard(new ArrayAdapter(), new LockFactory(new InMemoryStore()));
        $processingDuringSave = false;

        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(7);
        $asset->method('setStream')->willReturnSelf();
        $asset->method('save')->willReturnCallback(static function () use ($asset, $loopGuard, &$processingDuringSave) {
            $processingDuringSave = $loopGuard->isProcessingAsset(7);

            return $asset;
        });

        $this->restoreHealer($loopGuard)->callRestore($asset, $this->version(2, cid: 7));

        self::assertTrue($processingDuringSave, 'asset must be marked processing during the save');
        self::assertTrue($loopGuard->wasAssetRecentlyMoved(7), 'asset must be marked recently-moved after the save');
        self::assertFalse($loopGuard->isProcessingAsset(7), 'processing flag must be cleared after the save');
    }

    #[Test]
    public function restoreClearsTheProcessingFlagEvenWhenTheSaveThrows(): void
    {
        $loopGuard = new LoopGuard(new ArrayAdapter(), new LockFactory(new InMemoryStore()));

        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(7);
        $asset->method('setStream')->willReturnSelf();
        $asset->method('save')->willThrowException(new \RuntimeException('storage write failed'));

        try {
            $this->restoreHealer($loopGuard)->callRestore($asset, $this->version(2, cid: 7));
            self::fail('expected the save to throw');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertFalse($loopGuard->isProcessingAsset(7), 'processing flag must be cleared even on a failed save');
        self::assertFalse($loopGuard->wasAssetRecentlyMoved(7), 'a failed save must not mark the asset recently-moved');
    }

    /**
     * A healer that runs the REAL restore() (CID guard + LoopGuard-wrapped save), with only the
     * version byte-stream stubbed, exposed via a public proxy.
     */
    private function restoreHealer(?LoopGuard $loopGuard = null): VersionRollbackHealer
    {
        return new class ($this->checker(IntegrityStatus::Broken), $this->createMock(IntegrityHealLog::class), $loopGuard ?? new LoopGuard(new ArrayAdapter(), new LockFactory(new InMemoryStore())), $this->authorization()) extends VersionRollbackHealer {
            public function __construct(CompositeIntegrityChecker $checker, IntegrityHealLog $healLog, LoopGuard $loopGuard, ElementAuthorization $authorization)
            {
                parent::__construct($checker, $loopGuard, new ReviewedAssetLockCoordinator($loopGuard), new EventDispatcher(), $healLog, new NullLogger(), $authorization, new LoopGuardedAssetSaver($loopGuard));
            }

            public function callRestore(Asset $asset, Version $version): void
            {
                $this->restore($asset, $version);
            }

            protected function versionStream(Version $version)
            {
                $stream = fopen('php://memory', 'r+');
                fwrite($stream, 'restored-bytes');
                rewind($stream);

                return $stream;
            }
        };
    }

    private function authorization(bool $allowed = true): ElementAuthorization
    {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn(ActorContext::system());
        $authorization->method('isAllowed')->willReturn($allowed);

        return $authorization;
    }
}
