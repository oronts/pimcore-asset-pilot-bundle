<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Oronts\AssetPilotBundle\Enum\ObserverAuditReconciliationStatus;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryStatus;
use Oronts\AssetPilotBundle\Enum\OperationKind;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\DeliveryEnvelope;
use Oronts\AssetPilotBundle\Model\OperationHandle;
use Oronts\AssetPilotBundle\Model\OperationIntent;
use Oronts\AssetPilotBundle\Model\PreparedDelivery;
use Oronts\AssetPilotBundle\Observer\DurableOperationObserverInterface;
use Oronts\AssetPilotBundle\Observer\OperationObserverRegistry;
use Oronts\AssetPilotBundle\Security\ActorContextProvider;
use Oronts\AssetPilotBundle\Security\ActorContextStore;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\OperationDeliveryProcessor;
use Oronts\AssetPilotBundle\Service\OperationDeliveryStore;
use Oronts\AssetPilotBundle\Service\OperationDeliveryStoreInterface;
use Oronts\AssetPilotBundle\Service\OperationJournalInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

#[CoversClass(OperationDeliveryProcessor::class)]
final class OperationDeliveryProcessorTest extends TestCase
{
    private Connection $connection;
    private OperationDeliveryStore $store;
    private ActorContextStore $actors;
    private ElementAuthorization&MockObject $authorization;
    private Asset&MockObject $asset;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        OperationDeliveryStoreTest::createTable($this->connection);
        $this->store = new OperationDeliveryStore($this->connection);
        $provider = $this->createMock(ActorContextProvider::class);
        $provider->method('current')->willReturn(ActorContext::anonymous());
        $this->actors = new ActorContextStore($provider);
        $this->authorization = $this->createMock(ElementAuthorization::class);
        $this->asset = $this->createMock(Asset::class);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    #[Test]
    public function deliversUnderThePersistedActorAndRestoresThePreviousContext(): void
    {
        $seenActor = null;
        $seenDelivery = null;
        $observer = $this->observer('properties', function (DeliveryEnvelope $delivery) use (&$seenActor, &$seenDelivery): void {
            $seenActor = $this->actors->current();
            $seenDelivery = $delivery;
        });
        [$id, $registry] = $this->prepare($observer, 51, ActorContext::user(15));
        $this->authorization->expects(self::once())
            ->method('isAllowed')
            ->with($this->asset, 'publish', ActorContext::user(15))
            ->willReturn(true);

        self::assertSame(OperationDeliveryStatus::Delivered, $this->processor($registry)->process($id));
        self::assertEquals(ActorContext::user(15), $seenActor);
        self::assertInstanceOf(DeliveryEnvelope::class, $seenDelivery);
        self::assertSame($id, $seenDelivery->deliveryId);
        self::assertEquals(ActorContext::anonymous(), $this->actors->current());
        self::assertSame(OperationDeliveryStatus::Delivered->value, $this->row($id)['status']);
    }

    #[Test]
    public function retriesWithTheSameStableDeliveryIdThenMarksItDeadAtTheAttemptLimit(): void
    {
        $seenIds = [];
        $observer = $this->observer('properties', static function (DeliveryEnvelope $delivery) use (&$seenIds): never {
            $seenIds[] = $delivery->deliveryId;
            throw new \RuntimeException('temporarily unavailable');
        });
        [$id, $registry] = $this->prepare($observer, 52, ActorContext::system());
        $this->authorization->method('isAllowed')->willReturn(true);
        $processor = $this->processor($registry, maxAttempts: 2, baseRetrySeconds: 1, maxRetrySeconds: 1);

        self::assertSame(OperationDeliveryStatus::Retry, $processor->process($id));
        $this->connection->update(Installer::TABLE_OPERATION_DELIVERY, ['available_at' => '2000-01-01 00:00:00'], ['id' => $id]);
        self::assertSame(OperationDeliveryStatus::Dead, $processor->process($id));

        self::assertSame([$id, $id], $seenIds);
        self::assertSame(2, (int) $this->row($id)['attempts']);
        self::assertSame(OperationDeliveryStatus::Dead->value, $this->row($id)['status']);
        self::assertFalse($this->store->hasUnresolved(52));
    }

    #[Test]
    public function exponentialRetryDelayIsBounded(): void
    {
        $observer = $this->observer('properties', static fn (DeliveryEnvelope $delivery): never => throw new \RuntimeException('retry'));
        [$id, $registry] = $this->prepare($observer, 53, ActorContext::system());
        $this->authorization->method('isAllowed')->willReturn(true);
        $now = new \DateTimeImmutable('2030-01-01 00:00:00', new \DateTimeZone('UTC'));
        $processor = $this->processor($registry, maxAttempts: 4, baseRetrySeconds: 10, maxRetrySeconds: 15, now: $now);

        self::assertSame(OperationDeliveryStatus::Retry, $processor->process($id));
        self::assertSame('2030-01-01 00:00:10', $this->row($id)['available_at']);
        $this->connection->update(Installer::TABLE_OPERATION_DELIVERY, ['available_at' => '2000-01-01 00:00:00'], ['id' => $id]);
        self::assertSame(OperationDeliveryStatus::Retry, $processor->process($id));
        self::assertSame('2030-01-01 00:00:15', $this->row($id)['available_at']);
    }

    #[Test]
    public function revokedActorNeverRunsTheObserverOrFallsBackToSystem(): void
    {
        $delivered = false;
        $observer = $this->observer('properties', static function () use (&$delivered): void {
            $delivered = true;
        });
        [$id, $registry] = $this->prepare($observer, 54, ActorContext::user(19));
        $this->authorization->expects(self::once())
            ->method('isAllowed')
            ->willReturnCallback(function (Asset $asset, string $permission, ActorContext $actor): bool {
                self::assertSame($this->asset, $asset);
                self::assertSame('publish', $permission);
                self::assertEquals(ActorContext::user(19), $actor);
                self::assertEquals(ActorContext::user(19), $this->actors->current());

                return false;
            });

        self::assertSame(OperationDeliveryStatus::Dead, $this->processor($registry, maxAttempts: 1)->process($id));
        self::assertFalse($delivered);
        self::assertEquals(ActorContext::anonymous(), $this->actors->current());
    }

    #[Test]
    public function metadataFailureObserverRunsWithoutAnAssetAndRestoresTheActor(): void
    {
        $seenActor = null;
        $observer = $this->observer(
            'events',
            function (DeliveryEnvelope $delivery) use (&$seenActor): void {
                self::assertSame(OperationDeliveryOutcome::Failure, $delivery->outcome);
                $seenActor = $this->actors->current();
            },
            requiredPermission: null,
            outcome: OperationDeliveryOutcome::Failure,
        );
        [$id, $registry] = $this->prepare(
            $observer,
            56,
            ActorContext::user(23),
            OperationDeliveryOutcome::Failure,
        );
        $this->authorization->expects(self::never())->method('isAllowed');

        self::assertSame(
            OperationDeliveryStatus::Delivered,
            $this->processor($registry, assetExists: false)->process($id),
        );
        self::assertEquals(ActorContext::user(23), $seenActor);
        self::assertEquals(ActorContext::anonymous(), $this->actors->current());
    }

    #[Test]
    public function permissionedObserverDoesNotRunWhenItsAssetIsMissing(): void
    {
        $delivered = false;
        $observer = $this->observer('actions', static function () use (&$delivered): void {
            $delivered = true;
        });
        [$id, $registry] = $this->prepare($observer, 57, ActorContext::user(24));
        $this->authorization->expects(self::never())->method('isAllowed');

        self::assertSame(
            OperationDeliveryStatus::Dead,
            $this->processor($registry, maxAttempts: 1, assetExists: false)->process($id),
        );
        self::assertFalse($delivered);
        self::assertEquals(ActorContext::anonymous(), $this->actors->current());
        self::assertSame('The operation asset is unavailable for observer delivery.', $this->row($id)['last_error']);
    }

    #[Test]
    public function missingObserverIsDeadWithoutLoadingOrAuthorizingAnAsset(): void
    {
        $intent = $this->intent(ActorContext::system());
        $id = $this->store->prepare(new OperationHandle(55, $intent), [
            new PreparedDelivery('removed-observer', 'removed:0', OperationDeliveryOutcome::Success),
        ])[0];
        $this->store->activateForOutcome(55, OperationDeliveryOutcome::Success);
        $this->authorization->expects(self::never())->method('isAllowed');

        self::assertSame(OperationDeliveryStatus::Dead, $this->processor(new OperationObserverRegistry([]))->process($id));
        self::assertSame('The durable observer is not registered.', $this->row($id)['last_error']);
    }

    #[Test]
    public function expiredFinalAttemptIsDeadLetteredWithoutRunningTheObserverAgain(): void
    {
        $delivered = false;
        $observer = $this->observer('events', static function () use (&$delivered): void {
            $delivered = true;
        }, requiredPermission: null);
        [$id, $registry] = $this->prepare($observer, 59, ActorContext::system());
        $crashed = $this->store->claim($id, 'crashed-worker', 300);
        self::assertNotNull($crashed);
        $this->connection->update(
            Installer::TABLE_OPERATION_DELIVERY,
            ['locked_until' => '2000-01-01 00:00:00'],
            ['id' => $id],
        );
        $journal = $this->createMock(OperationJournalInterface::class);
        $journal->expects(self::once())
            ->method('recordObserverFailure')
            ->with(59, 'events')
            ->willReturn(ObserverAuditReconciliationStatus::Recorded);

        self::assertSame(
            OperationDeliveryStatus::Dead,
            $this->processor($registry, maxAttempts: 1, journal: $journal)->process($id),
        );
        self::assertFalse($delivered);
        self::assertSame(1, (int) $this->row($id)['attempts']);
    }

    #[Test]
    public function deadAuditReconciliationSurvivesFailureWithoutRepeatingTheObserver(): void
    {
        $deliveries = 0;
        $observer = $this->observer('events', static function () use (&$deliveries): never {
            ++$deliveries;
            throw new \RuntimeException('observer failed');
        }, requiredPermission: null);
        [$id, $registry] = $this->prepare($observer, 61, ActorContext::system());
        $reconciliations = 0;
        $journal = $this->createMock(OperationJournalInterface::class);
        $journal->expects(self::exactly(2))
            ->method('recordObserverFailure')
            ->with(61, 'events')
            ->willReturnCallback(static function () use (&$reconciliations): ObserverAuditReconciliationStatus {
                ++$reconciliations;
                if ($reconciliations === 1) {
                    throw new \RuntimeException('audit unavailable');
                }

                return ObserverAuditReconciliationStatus::Recorded;
            });
        $processor = $this->processor($registry, maxAttempts: 1, journal: $journal);

        self::assertSame(OperationDeliveryStatus::Dead, $processor->process($id));
        self::assertSame(1, $deliveries);
        self::assertSame([], $this->store->due());
        self::assertNull($this->row($id)['audit_reconciled_at']);

        $this->connection->update(
            Installer::TABLE_OPERATION_DELIVERY,
            ['available_at' => '2000-01-01 00:00:00'],
            ['id' => $id],
        );
        self::assertSame([$id], $this->store->due());

        self::assertSame(OperationDeliveryStatus::Dead, $processor->process($id));
        self::assertSame(1, $deliveries);
        self::assertSame([], $this->store->due());
        self::assertNotNull($this->row($id)['audit_reconciled_at']);
    }

    #[Test]
    public function deliveredAuditReconciliationSurvivesFailureWithoutRepeatingTheObserver(): void
    {
        $deliveries = 0;
        $observer = $this->observer('events', static function () use (&$deliveries): void {
            ++$deliveries;
        }, requiredPermission: null);
        [$id, $registry] = $this->prepare($observer, 62, ActorContext::system());
        $reconciliations = 0;
        $journal = $this->createMock(OperationJournalInterface::class);
        $journal->expects(self::exactly(2))
            ->method('resolveObserverFailures')
            ->with(62)
            ->willReturnCallback(static function () use (&$reconciliations): ObserverAuditReconciliationStatus {
                ++$reconciliations;
                if ($reconciliations === 1) {
                    throw new \RuntimeException('audit unavailable');
                }

                return ObserverAuditReconciliationStatus::Recorded;
            });
        $processor = $this->processor($registry, journal: $journal);

        self::assertSame(OperationDeliveryStatus::Delivered, $processor->process($id));
        self::assertSame(1, $deliveries);
        self::assertSame([], $this->store->due());
        self::assertNull($this->row($id)['audit_reconciled_at']);

        $this->connection->update(
            Installer::TABLE_OPERATION_DELIVERY,
            ['available_at' => '2000-01-01 00:00:00'],
            ['id' => $id],
        );
        self::assertSame([$id], $this->store->due());
        self::assertSame(OperationDeliveryStatus::Delivered, $processor->process($id));
        self::assertSame(1, $deliveries);
        self::assertSame([], $this->store->due());
        self::assertNotNull($this->row($id)['audit_reconciled_at']);
    }

    #[Test]
    public function observerCanHeartbeatDuringLongRunningDelivery(): void
    {
        $observer = $this->observer(
            'events',
            static fn (DeliveryEnvelope $delivery) => $delivery->heartbeat(),
            requiredPermission: null,
        );
        [$id, $registry] = $this->prepare($observer, 60, ActorContext::system());
        $store = new class ($this->connection) extends OperationDeliveryStore {
            public int $renewals = 0;

            public function renewLease(DeliveryEnvelope $delivery, int $leaseSeconds): bool
            {
                ++$this->renewals;

                return parent::renewLease($delivery, $leaseSeconds);
            }
        };

        self::assertSame(OperationDeliveryStatus::Delivered, $this->processor($registry, deliveries: $store)->process($id));
        self::assertSame(3, $store->renewals);
    }

    #[Test]
    public function successfulDeliveryReconcilesTheOperationWarning(): void
    {
        $observer = $this->observer('events', static function (): void {}, requiredPermission: null);
        [$id, $registry] = $this->prepare($observer, 58, ActorContext::system());
        $journal = $this->createMock(OperationJournalInterface::class);
        $journal->expects(self::once())->method('resolveObserverFailures')->with(58)->willReturn(ObserverAuditReconciliationStatus::Recorded);

        self::assertSame(
            OperationDeliveryStatus::Delivered,
            $this->processor($registry, journal: $journal)->process($id),
        );
    }

    /** @return array{string, OperationObserverRegistry} */
    private function prepare(
        DurableOperationObserverInterface $observer,
        int $operationId,
        ActorContext $actor,
        OperationDeliveryOutcome $outcome = OperationDeliveryOutcome::Success,
    ): array {
        $registry = new OperationObserverRegistry([$observer]);
        $intent = $this->intent($actor);
        $id = $this->store->prepare(new OperationHandle($operationId, $intent), $registry->prepare($intent))[0];
        $this->store->activateForOutcome($operationId, $outcome);

        return [$id, $registry];
    }

    private function observer(
        string $id,
        \Closure $delivery,
        ?string $requiredPermission = 'publish',
        OperationDeliveryOutcome $outcome = OperationDeliveryOutcome::Success,
    ): DurableOperationObserverInterface {
        return new class ($id, $delivery, $requiredPermission, $outcome) implements DurableOperationObserverInterface {
            public function __construct(
                private readonly string $observerId,
                private readonly \Closure $delivery,
                private readonly ?string $requiredPermission,
                private readonly OperationDeliveryOutcome $outcome,
            ) {}

            public function id(): string
            {
                return $this->observerId;
            }

            public function requiredAssetPermission(): ?string
            {
                return $this->requiredPermission;
            }

            public function prepare(OperationIntent $intent): iterable
            {
                return [new PreparedDelivery($this->observerId, $this->observerId . ':0', $this->outcome)];
            }

            public function deliver(DeliveryEnvelope $delivery): void
            {
                ($this->delivery)($delivery);
            }
        };
    }

    private function processor(
        OperationObserverRegistry $registry,
        int $maxAttempts = 5,
        int $baseRetrySeconds = 30,
        int $maxRetrySeconds = 3_600,
        ?\DateTimeImmutable $now = null,
        bool $assetExists = true,
        ?OperationJournalInterface $journal = null,
        ?OperationDeliveryStoreInterface $deliveries = null,
    ): OperationDeliveryProcessor {
        if ($journal === null) {
            $journal = $this->createMock(OperationJournalInterface::class);
            $journal->method('recordObserverFailure')->willReturn(ObserverAuditReconciliationStatus::NotApplicable);
            $journal->method('resolveObserverFailures')->willReturn(ObserverAuditReconciliationStatus::NotApplicable);
        }

        return new class (
            $deliveries ?? $this->store,
            $registry,
            $this->actors,
            $this->authorization,
            $this->loopGuard(),
            $journal,
            new NullLogger(),
            $assetExists ? $this->asset : null,
            $now,
            $maxAttempts,
            $baseRetrySeconds,
            $maxRetrySeconds,
        ) extends OperationDeliveryProcessor {
            public function __construct(
                OperationDeliveryStoreInterface $deliveries,
                OperationObserverRegistry $observers,
                ActorContextStore $actors,
                ElementAuthorization $authorization,
                LoopGuard $loopGuard,
                OperationJournalInterface $journal,
                LoggerInterface $logger,
                private readonly ?Asset $asset,
                private readonly ?\DateTimeImmutable $currentTime,
                int $maxAttempts,
                int $baseRetrySeconds,
                int $maxRetrySeconds,
            ) {
                parent::__construct(
                    $deliveries,
                    $observers,
                    $actors,
                    $authorization,
                    $loopGuard,
                    $journal,
                    $logger,
                    $maxAttempts,
                    $baseRetrySeconds,
                    $maxRetrySeconds,
                );
            }

            protected function loadAsset(int $assetId): ?Asset
            {
                return $this->asset;
            }

            protected function now(): \DateTimeImmutable
            {
                return $this->currentTime ?? parent::now();
            }
        };
    }

    private function loopGuard(): LoopGuard
    {
        $guard = $this->createMock(LoopGuard::class);
        $guard->method('acquireAsset')->willReturn(true);

        return $guard;
    }

    private function intent(ActorContext $actor): OperationIntent
    {
        return new OperationIntent(
            OperationKind::Move,
            7,
            '/source/a.jpg',
            '/target/a.jpg',
            9,
            'Product',
            'images',
            TriggerType::Manual,
            $actor,
            ['preparedValue' => 'immutable'],
        );
    }

    /** @return array<string, mixed> */
    private function row(string $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . Installer::TABLE_OPERATION_DELIVERY . ' WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }
}
