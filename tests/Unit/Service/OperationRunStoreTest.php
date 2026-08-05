<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunKind;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Service\OperationRunStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperationRunStore::class)]
final class OperationRunStoreTest extends TestCase
{
    private Connection $connection;
    private OperationRunStore $store;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new Schema();
        Installer::ensureCurrentSchema($schema);
        $schemaManager = $this->connection->createSchemaManager();
        $schemaManager->createTable($schema->getTable(Installer::TABLE_OPERATION_RUN));
        $schemaManager->createTable($schema->getTable(Installer::TABLE_OPERATION_RUN_ITEM));
        $this->store = new OperationRunStore($this->connection);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    #[Test]
    public function rootIdWalksTheRetryChainToItsOriginAndIsStableAcrossRetryOfRetry(): void
    {
        $item = [['key' => 'object:10', 'type' => 'data_object', 'id' => 10]];
        $root = $this->store->create(OperationRunKind::Organize, ActorContext::user(7), $item);
        $child = $this->store->create(OperationRunKind::Organize, ActorContext::user(7), $item, retryOf: $root);
        $grandchild = $this->store->create(OperationRunKind::Organize, ActorContext::user(7), $item, retryOf: $child);

        self::assertSame($root, $this->store->rootId($root));
        self::assertSame($root, $this->store->rootId($child));
        self::assertSame($root, $this->store->rootId($grandchild), 'retry-of-retry must resolve to the same root');
        self::assertSame('unknown-run', $this->store->rootId('unknown-run'), 'a missing run roots to itself');
    }

    #[Test]
    public function dueForDispatchReturnsOnlyPendingRunsAndMarkDispatchedIsIdempotent(): void
    {
        $actor = ActorContext::user(7);
        $pending = $this->store->create(
            OperationRunKind::Organize,
            $actor,
            [['key' => 'object:10', 'type' => 'data_object', 'id' => 10, 'fingerprint' => 'fp10', 'payload' => ['trigger' => 'object_save']]],
            ['trigger' => 'object_save'],
            null,
            OperationRunStatus::PendingDispatch,
        );
        $this->store->create(OperationRunKind::Organize, $actor, [['key' => 'object:20', 'type' => 'data_object', 'id' => 20]]);

        $due = $this->store->dueForDispatch(50);
        self::assertCount(1, $due, 'only pending-dispatch runs are due; a Queued run is not');
        self::assertSame($pending, $due[0]['id']);
        self::assertSame('object_save', $due[0]['trigger']);
        self::assertSame(7, $due[0]['actorUserId']);
        self::assertSame([['id' => 10, 'fingerprint' => 'fp10']], $due[0]['targets']);

        self::assertTrue($this->store->markDispatched($pending), 'the first mark owns the transition');
        self::assertFalse($this->store->markDispatched($pending), 'a duplicate mark is a no-op');
        self::assertSame([], $this->store->dueForDispatch(50), 'a dispatched run is no longer due');
    }

    #[Test]
    public function cancellingAPendingDispatchRunTerminatesItImmediatelyAndStopsItBeingRelayed(): void
    {
        $actor = ActorContext::user(7);
        $run = $this->store->create(
            OperationRunKind::Organize,
            $actor,
            [['key' => 'object:10', 'type' => 'data_object', 'id' => 10]],
            [],
            null,
            OperationRunStatus::PendingDispatch,
        );

        // A pending run has no worker to resolve a CancelRequested, so cancellation terminates it directly and
        // it is never relayed.
        self::assertTrue($this->store->requestCancellation($run, $actor));
        self::assertSame(OperationRunStatus::Cancelled, $this->store->finish($run));
        self::assertSame([], $this->store->dueForDispatch(50), 'a cancelled pending run is no longer due for dispatch');
        self::assertSame(OperationRunItemStatus::Cancelled->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN_ITEM . ' WHERE run_id = ?', [$run]), 'the queued item is terminalized, not left non-terminal');
        self::assertNotNull($this->store->retry($run, $actor), 'a cancelled pending run stays retryable because its item is terminal');
    }

    #[Test]
    public function resumeAcceptsAPendingDispatchRunSoAFastWorkerNeverStrandsItInThePublishWindow(): void
    {
        $run = $this->store->create(
            OperationRunKind::Organize,
            ActorContext::user(7),
            [['key' => 'object:10', 'type' => 'data_object', 'id' => 10]],
            [],
            null,
            OperationRunStatus::PendingDispatch,
        );

        // The relay publishes the message just before markDispatched; a fast worker that resumes the still
        // pending run in that window must start it, and the relay's later markDispatched must then no-op.
        self::assertTrue($this->store->resume($run), 'a fast worker must be able to start a still-pending run');
        self::assertFalse($this->store->markDispatched($run), 'markDispatched is a no-op once the run is running');
    }

    #[Test]
    public function reconcileExpiredItemLeasesFailsAnExpiredItemAndFinishesItsRunButLeavesALiveOneRunning(): void
    {
        $store = new OperationRunStore($this->connection, leaseSeconds: 300);

        $expired = $store->create(OperationRunKind::Organize, ActorContext::user(7), [
            ['key' => 'object:10', 'type' => 'data_object', 'id' => 10, 'payload' => ['trigger' => 'api']],
        ]);
        $healthy = $store->create(OperationRunKind::Organize, ActorContext::user(7), [
            ['key' => 'object:11', 'type' => 'data_object', 'id' => 11, 'payload' => ['trigger' => 'api']],
        ]);

        self::assertTrue($store->start($expired));
        self::assertTrue($store->startItem($expired, 'object:10', 'token-expired'));
        self::assertTrue($store->start($healthy));
        self::assertTrue($store->startItem($healthy, 'object:11', 'token-healthy'));

        // The expired item's worker died, so its lease elapsed; the healthy item is mid-flight with a live lease.
        $this->connection->executeStatement('UPDATE ' . Installer::TABLE_OPERATION_RUN_ITEM . ' SET lease_expires_at = ? WHERE run_id = ?', ['2020-01-01 00:00:00', $expired]);

        self::assertSame(1, $store->reconcileExpiredItemLeases(100));

        self::assertSame(OperationRunItemStatus::Failed->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN_ITEM . ' WHERE run_id = ?', [$expired]));
        self::assertNull($this->connection->fetchOne('SELECT claim_token FROM ' . Installer::TABLE_OPERATION_RUN_ITEM . ' WHERE run_id = ?', [$expired]), 'the expired lease is cleared');
        self::assertSame(OperationRunStatus::Partial->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?', [$expired]), 'the run terminalizes from its item outcomes');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT failed_count FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?', [$expired]), 'counts are refreshed');

        self::assertSame(OperationRunItemStatus::Running->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN_ITEM . ' WHERE run_id = ?', [$healthy]), 'a live-lease item keeps running');
        self::assertSame(OperationRunStatus::Running->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?', [$healthy]));
    }

    #[Test]
    public function reconcileExpiredItemLeasesLeavesALongRunningItemWithAFreshLeaseUntouched(): void
    {
        $store = new OperationRunStore($this->connection, leaseSeconds: 300);
        $runId = $store->create(OperationRunKind::Organize, ActorContext::user(7), [
            ['key' => 'object:10', 'type' => 'data_object', 'id' => 10],
        ]);
        self::assertTrue($store->start($runId));
        self::assertTrue($store->startItem($runId, 'object:10', 'token-live'));

        // The item has processed for over an hour (old updated_at) but the worker keeps renewing its lease,
        // so the lease is still in the future. The old age-based reconcile failed this legitimate work.
        $this->connection->executeStatement(
            'UPDATE ' . Installer::TABLE_OPERATION_RUN_ITEM . ' SET updated_at = ?, lease_expires_at = ? WHERE run_id = ?',
            ['2020-01-01 00:00:00', '2999-01-01 00:00:00', $runId],
        );

        self::assertSame(0, $store->reconcileExpiredItemLeases(100));
        self::assertSame(OperationRunItemStatus::Running->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN_ITEM . ' WHERE run_id = ?', [$runId]));
        self::assertSame(OperationRunStatus::Running->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?', [$runId]));
    }

    #[Test]
    public function reconcileExpiredItemLeasesDoesNotFailAQueuedBacklogRun(): void
    {
        $store = new OperationRunStore($this->connection, leaseSeconds: 300);
        $runId = $store->create(OperationRunKind::Organize, ActorContext::user(7), [
            ['key' => 'object:10', 'type' => 'data_object', 'id' => 10],
        ]);

        // A queued message can wait in a broker backlog for over an hour before a worker claims it. Its item
        // has no lease yet, so reconciliation must not terminalize it (the old age-based reconcile did).
        $this->connection->executeStatement('UPDATE ' . Installer::TABLE_OPERATION_RUN . ' SET updated_at = ? WHERE id = ?', ['2020-01-01 00:00:00', $runId]);
        $this->connection->executeStatement('UPDATE ' . Installer::TABLE_OPERATION_RUN_ITEM . ' SET updated_at = ? WHERE run_id = ?', ['2020-01-01 00:00:00', $runId]);

        self::assertSame(0, $store->reconcileExpiredItemLeases(100));
        self::assertSame(OperationRunStatus::Queued->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?', [$runId]));
        self::assertSame(OperationRunItemStatus::Queued->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN_ITEM . ' WHERE run_id = ?', [$runId]));
    }

    #[Test]
    public function reconcileAbandonedRunningRunsFailsAStaleRunningRunButSparesFreshAndQueuedOnes(): void
    {
        $store = new OperationRunStore($this->connection, leaseSeconds: 300);

        // A synchronous run whose process crashed: started to Running, its item never drained, and its
        // updated_at is stale because no worker and no message will ever progress it.
        $stranded = $store->create(OperationRunKind::DuplicateMerge, ActorContext::user(7), [
            ['key' => 'object:10', 'type' => 'data_object', 'id' => 10],
        ]);
        self::assertTrue($store->start($stranded));
        $this->connection->executeStatement('UPDATE ' . Installer::TABLE_OPERATION_RUN . ' SET updated_at = ? WHERE id = ?', ['2020-01-01 00:00:00', $stranded]);

        // A run a worker is actively progressing has a recent updated_at and must be left alone.
        $fresh = $store->create(OperationRunKind::Organize, ActorContext::user(7), [
            ['key' => 'object:20', 'type' => 'data_object', 'id' => 20],
        ]);
        self::assertTrue($store->start($fresh));

        // A queued backlog run is the worker/relay's to claim, not this reconciler's, even when stale.
        $queued = $store->create(OperationRunKind::Organize, ActorContext::user(7), [
            ['key' => 'object:30', 'type' => 'data_object', 'id' => 30],
        ]);
        $this->connection->executeStatement('UPDATE ' . Installer::TABLE_OPERATION_RUN . ' SET updated_at = ? WHERE id = ?', ['2020-01-01 00:00:00', $queued]);

        self::assertSame(1, $store->reconcileAbandonedRunningRuns(100, 3600));

        self::assertSame(OperationRunStatus::Failed->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?', [$stranded]), 'the stale Running run is failed');
        self::assertSame(OperationRunItemStatus::Failed->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN_ITEM . ' WHERE run_id = ?', [$stranded]), 'its stranded queued item is failed');
        self::assertSame(OperationRunStatus::Running->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?', [$fresh]), 'a fresh Running run is spared');
        self::assertSame(OperationRunStatus::Queued->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?', [$queued]), 'a queued backlog run is spared');
    }

    #[Test]
    public function reconcileAbandonedRunningRunsSparesARunWhoseInFlightItemStillHoldsALiveLease(): void
    {
        $store = new OperationRunStore($this->connection, leaseSeconds: 300);

        // A bulk run drains objects one at a time: object:10 is Running under a live, heartbeating lease while
        // object:11 is still Queued. run.updated_at is frozen at the previous item's completion so it looks
        // stale, but a live worker still owns the in-flight item and the run must not be failed under it.
        $runId = $store->create(OperationRunKind::Organize, ActorContext::user(7), [
            ['key' => 'object:10', 'type' => 'data_object', 'id' => 10],
            ['key' => 'object:11', 'type' => 'data_object', 'id' => 11],
        ]);
        self::assertTrue($store->start($runId));
        self::assertTrue($store->startItem($runId, 'object:10', 'live-worker'));
        $this->connection->executeStatement('UPDATE ' . Installer::TABLE_OPERATION_RUN . ' SET updated_at = ? WHERE id = ?', ['2020-01-01 00:00:00', $runId]);

        self::assertSame(0, $store->reconcileAbandonedRunningRuns(100, 3600), 'a run with a live-leased in-flight item is not abandoned');

        self::assertSame(OperationRunStatus::Running->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?', [$runId]));
        self::assertSame(OperationRunItemStatus::Running->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN_ITEM . ' WHERE run_id = ? AND item_key = ?', [$runId, 'object:10']), 'the live in-flight item is untouched');
        self::assertSame(OperationRunItemStatus::Queued->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN_ITEM . ' WHERE run_id = ? AND item_key = ?', [$runId, 'object:11']), 'the queued sibling is untouched');
    }

    #[Test]
    public function reconcileAbandonedRunningRunsFailsARunWhoseInFlightLeaseHasExpired(): void
    {
        $store = new OperationRunStore($this->connection, leaseSeconds: 300);

        // The worker died: object:10's lease expired with no heartbeat and object:11 never drained. Its
        // in-flight item is provably dead, so the run is genuinely abandoned and must be reconciled.
        $runId = $store->create(OperationRunKind::Organize, ActorContext::user(7), [
            ['key' => 'object:10', 'type' => 'data_object', 'id' => 10],
            ['key' => 'object:11', 'type' => 'data_object', 'id' => 11],
        ]);
        self::assertTrue($store->start($runId));
        self::assertTrue($store->startItem($runId, 'object:10', 'dead-worker'));
        $this->connection->executeStatement('UPDATE ' . Installer::TABLE_OPERATION_RUN_ITEM . ' SET lease_expires_at = ? WHERE run_id = ? AND item_key = ?', ['2000-01-01 00:00:00', $runId, 'object:10']);
        $this->connection->executeStatement('UPDATE ' . Installer::TABLE_OPERATION_RUN . ' SET updated_at = ? WHERE id = ?', ['2020-01-01 00:00:00', $runId]);

        self::assertSame(1, $store->reconcileAbandonedRunningRuns(100, 3600), 'a run whose in-flight lease has expired with a queued sibling is abandoned');
        self::assertSame(OperationRunStatus::Failed->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?', [$runId]));
    }

    #[Test]
    public function failAbandonedRunRevalidatesTheLeaseSoAReclaimedRunIsSparedButAnExpiredOneIsFailed(): void
    {
        // The batch scan lists a run, then a worker reclaims its item under a fresh lease before the fail (the
        // interleaving race). failAbandonedRun re-validates the abandonment predicate transactionally: a live
        // in-flight lease spares the run; only once it has expired is the run terminalized.
        $store = new OperationRunStore($this->connection, leaseSeconds: 300);
        $runId = $store->create(OperationRunKind::Organize, ActorContext::user(7), [
            ['key' => 'object:10', 'type' => 'data_object', 'id' => 10],
            ['key' => 'object:11', 'type' => 'data_object', 'id' => 11],
        ]);
        self::assertTrue($store->start($runId));
        self::assertTrue($store->startItem($runId, 'object:10', 'live-worker'));
        $this->connection->executeStatement('UPDATE ' . Installer::TABLE_OPERATION_RUN . ' SET updated_at = ? WHERE id = ?', ['2020-01-01 00:00:00', $runId]);

        self::assertFalse($store->failAbandonedRun($runId, '2025-01-01 00:00:00', '2020-01-01 00:00:00', 'err'), 'a live in-flight lease spares the run');
        self::assertSame(OperationRunStatus::Running->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?', [$runId]));
        self::assertSame(OperationRunItemStatus::Running->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN_ITEM . ' WHERE run_id = ? AND item_key = ?', [$runId, 'object:10']));

        $this->connection->executeStatement('UPDATE ' . Installer::TABLE_OPERATION_RUN_ITEM . ' SET lease_expires_at = ? WHERE run_id = ? AND item_key = ?', ['2000-01-01 00:00:00', $runId, 'object:10']);

        self::assertTrue($store->failAbandonedRun($runId, '2025-01-01 00:00:00', '2020-01-01 00:00:00', 'err'), 'an expired in-flight lease is genuinely abandoned and fails');
        self::assertSame(OperationRunStatus::Failed->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?', [$runId]));
    }

    #[Test]
    public function startAndResumeItemTakeTheRunLockSoAFailedRunRejectsLateClaims(): void
    {
        // startItem/resumeItem claim under the run row lock, so once reconciliation fails a run no late worker
        // can claim or reclaim its items (the post-fail half of the claim/reconcile serialization). A missing
        // run declines the claim without erroring, exercising the tryLockRun-false path.
        $store = new OperationRunStore($this->connection, leaseSeconds: 300);
        $runId = $store->create(OperationRunKind::Organize, ActorContext::user(7), [
            ['key' => 'object:10', 'type' => 'data_object', 'id' => 10],
            ['key' => 'object:11', 'type' => 'data_object', 'id' => 11],
        ]);
        self::assertTrue($store->start($runId));
        $store->fail($runId, 'reconciled');

        self::assertFalse($store->startItem($runId, 'object:10', 'late-worker'), 'a failed run rejects a new claim');
        self::assertFalse($store->resumeItem($runId, 'object:11', 'late-worker'), 'a failed run rejects a reclaim');
        self::assertFalse($store->startItem('missing-run', 'object:10', 'late-worker'), 'a missing run declines the claim without erroring');
    }

    #[Test]
    public function aReclaimedItemLeaseFencesThePreviousWorkersRenewalAndCompletion(): void
    {
        $store = new OperationRunStore($this->connection, leaseSeconds: 300);
        $runId = $store->create(OperationRunKind::Organize, ActorContext::user(7), [
            ['key' => 'object:10', 'type' => 'data_object', 'id' => 10],
        ]);
        self::assertTrue($store->start($runId));
        self::assertTrue($store->startItem($runId, 'object:10', 'worker-one'));

        // A redelivery hands the item to a second worker, which reclaims it under its own token.
        self::assertTrue($store->resumeItem($runId, 'object:10', 'worker-two'));

        // The original worker is now a zombie: it can neither renew nor complete the item.
        self::assertFalse($store->renewItemLease($runId, 'object:10', 'worker-one'));
        self::assertFalse($store->completeItem($runId, 'object:10', OperationRunItemStatus::Completed, token: 'worker-one'));
        self::assertSame(OperationRunItemStatus::Running->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN_ITEM . ' WHERE run_id = ?', [$runId]));

        // The current owner controls the item.
        self::assertTrue($store->renewItemLease($runId, 'object:10', 'worker-two'));
        self::assertTrue($store->completeItem($runId, 'object:10', OperationRunItemStatus::Completed, token: 'worker-two'));
        self::assertSame(OperationRunItemStatus::Completed->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN_ITEM . ' WHERE run_id = ?', [$runId]));
    }

    #[Test]
    public function aTokenlessCompletionSparesALiveLeaseButReclaimsAnExpiredOne(): void
    {
        $store = new OperationRunStore($this->connection, leaseSeconds: 300);
        $runId = $store->create(OperationRunKind::Organize, ActorContext::user(7), [
            ['key' => 'object:10', 'type' => 'data_object', 'id' => 10],
        ]);
        self::assertTrue($store->start($runId));
        self::assertTrue($store->startItem($runId, 'object:10', 'worker-one'));

        // A redelivery's tokenless failBatch cleanup must not overwrite an item a live worker still holds.
        self::assertFalse($store->completeItem($runId, 'object:10', OperationRunItemStatus::Failed, token: null));
        self::assertSame(OperationRunItemStatus::Running->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN_ITEM . ' WHERE run_id = ?', [$runId]), 'a live lease is spared');

        // Once the lease has expired, the item is provably abandoned and a tokenless cleanup reclaims it.
        $this->connection->update(Installer::TABLE_OPERATION_RUN_ITEM, ['lease_expires_at' => '2000-01-01 00:00:00'], ['run_id' => $runId]);
        self::assertTrue($store->completeItem($runId, 'object:10', OperationRunItemStatus::Failed, token: null));
        self::assertSame(OperationRunItemStatus::Failed->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN_ITEM . ' WHERE run_id = ?', [$runId]), 'an expired lease is reclaimed');
    }

    #[Test]
    public function reconcileUnfinalizedRunsFinalizesARunWhoseItemsAreAllTerminalButWasNeverFinished(): void
    {
        $store = new OperationRunStore($this->connection);

        $stranded = $store->create(OperationRunKind::Organize, ActorContext::user(7), [['key' => 'object:10', 'type' => 'data_object', 'id' => 10]]);
        $active = $store->create(OperationRunKind::Organize, ActorContext::user(7), [['key' => 'object:11', 'type' => 'data_object', 'id' => 11]]);

        // The stranded run's only item completed, but finish() was never called (a throw or a process restart
        // between completeItem and finish leaves the run non-terminal with all-terminal items).
        self::assertTrue($store->start($stranded));
        self::assertTrue($store->startItem($stranded, 'object:10', 'token'));
        self::assertTrue($store->completeItem($stranded, 'object:10', OperationRunItemStatus::Completed, token: 'token'));
        self::assertSame(OperationRunStatus::Running->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?', [$stranded]), 'precondition: the run is stranded in Running');

        // The active run is still processing (its item is running), so it must NOT be finalized.
        self::assertTrue($store->start($active));
        self::assertTrue($store->startItem($active, 'object:11', 'token'));

        self::assertSame(1, $store->reconcileUnfinalizedRuns(100));

        self::assertSame(OperationRunStatus::Completed->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?', [$stranded]), 'the stranded run is finalized from its item outcomes');
        self::assertSame(OperationRunStatus::Running->value, $this->connection->fetchOne('SELECT status FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE id = ?', [$active]), 'a run with an in-flight item is left running');
    }

    #[Test]
    public function countRunsQueuedLongerThanCountsAwaitingDispatchAndQueuedRuns(): void
    {
        $store = new OperationRunStore($this->connection);

        $stale = $store->create(OperationRunKind::Organize, ActorContext::user(7), [['key' => 'object:10', 'type' => 'data_object', 'id' => 10]]);
        $store->create(OperationRunKind::Organize, ActorContext::user(7), [['key' => 'object:11', 'type' => 'data_object', 'id' => 11]]);
        $running = $store->create(OperationRunKind::Organize, ActorContext::user(7), [['key' => 'object:12', 'type' => 'data_object', 'id' => 12]]);
        $pending = $store->create(OperationRunKind::Organize, ActorContext::user(7), [['key' => 'object:13', 'type' => 'data_object', 'id' => 13]], [], null, OperationRunStatus::PendingDispatch);

        // The stale (queued), running, and pending-dispatch runs were created two hours ago; the second run
        // just arrived. The running one started, so it is no longer queued even though it was created long ago.
        $twoHoursAgo = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-2 hours')->format('Y-m-d H:i:s');
        $this->connection->executeStatement('UPDATE ' . Installer::TABLE_OPERATION_RUN . ' SET created_at = ? WHERE id IN (?, ?, ?)', [$twoHoursAgo, $stale, $running, $pending]);
        self::assertTrue($store->start($running));

        self::assertSame(2, $store->countRunsQueuedLongerThan(3600), 'the long-queued AND the long pending-dispatch runs count (2h old > 1h window)');
        self::assertSame(0, $store->countRunsQueuedLongerThan(864000), 'the 2h-old runs are inside the 10-day window, so nothing counts');
    }

    #[Test]
    public function createPersistsRequestTargetsAndPayloads(): void
    {
        $runId = $this->store->create(
            OperationRunKind::Organize,
            ActorContext::user(7),
            [
                ['key' => 'object:10', 'type' => 'data_object', 'id' => 10, 'fingerprint' => 'version:4', 'payload' => ['trigger' => 'api']],
                ['key' => 'object:11', 'type' => 'data_object', 'id' => 11, 'payload' => ['trigger' => 'manual']],
            ],
            ['dryRun' => false, 'filters' => ['class' => 'Product']],
        );

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $runId);
        $run = $this->requiredRun($runId, ActorContext::user(7));
        self::assertSame(OperationRunKind::Organize->value, $run['kind']);
        self::assertSame(OperationRunStatus::Queued->value, $run['status']);
        self::assertSame(2, (int) $run['total_count']);
        self::assertSame(0, (int) $run['processed_count']);
        self::assertSame(1, (int) $run['attempt']);
        self::assertNull($run['retry_of']);
        self::assertSame(['dryRun' => false, 'filters' => ['class' => 'Product']], $run['request_payload']);
        self::assertSame('version:4', $run['items'][0]['fingerprint']);
        self::assertSame(['trigger' => 'api'], $run['items'][0]['payload']);
        self::assertSame(['trigger' => 'manual'], $run['items'][1]['payload']);
    }

    #[Test]
    public function recordsASimulationAsACompletedRunThatIsNeverDueForDispatch(): void
    {
        $actor = ActorContext::user(7);
        $runId = $this->store->create(
            OperationRunKind::Simulation,
            $actor,
            [[
                'key' => 'asset:12',
                'type' => 'asset',
                'id' => 12,
                'fingerprint' => 'fp12',
                'state' => ['from' => '/Uploads/a.jpg', 'to' => '/Photos/a.jpg', 'ruleName' => 'images'],
            ]],
            ['objectId' => 99],
            initialStatus: OperationRunStatus::Completed,
        );

        $run = $this->requiredRun($runId, $actor);
        self::assertSame(OperationRunKind::Simulation->value, $run['kind']);
        self::assertSame(OperationRunStatus::Completed->value, $run['status']);
        self::assertSame(1, (int) $run['total_count']);
        self::assertSame(1, (int) $run['processed_count'], 'a terminal run reports processed == total, not a stalled 0/N');
        self::assertSame(1, (int) $run['succeeded_count']);
        self::assertSame(
            ['from' => '/Uploads/a.jpg', 'to' => '/Photos/a.jpg', 'ruleName' => 'images'],
            $run['items'][0]['state_payload'],
            'the from/to diff is recorded in item state for later review',
        );
        self::assertSame([], $this->store->dueForDispatch(50), 'a completed simulation is never published to a worker');
    }

    #[Test]
    public function recentFiltersByKindWhenRequested(): void
    {
        $actor = ActorContext::user(7);
        $item = [['key' => 'asset:1', 'type' => 'asset', 'id' => 1]];
        $this->store->create(OperationRunKind::Organize, $actor, $item, initialStatus: OperationRunStatus::Completed);
        $simulation = $this->store->create(OperationRunKind::Simulation, $actor, $item, initialStatus: OperationRunStatus::Completed);

        self::assertCount(2, $this->store->recent($actor), 'the unfiltered listing returns both runs');

        $simulations = $this->store->recent($actor, 20, OperationRunKind::Simulation);
        self::assertCount(1, $simulations);
        self::assertSame($simulation, $simulations[0]['id'], 'the kind filter returns only simulation runs');
    }

    #[Test]
    public function invalidItemRollsBackTheEntireCreate(): void
    {
        try {
            $this->store->create(OperationRunKind::Organize, ActorContext::system(), [
                ['key' => 'object:10', 'type' => 'data_object'],
                ['key' => '', 'type' => 'data_object'],
            ]);
            self::fail('Expected invalid operation run item to be rejected.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . Installer::TABLE_OPERATION_RUN));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . Installer::TABLE_OPERATION_RUN_ITEM));
    }

    #[Test]
    public function rejectsARetryWhoseParentDoesNotExist(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->create(
            OperationRunKind::Organize,
            ActorContext::system(),
            [['key' => 'object:1', 'type' => 'data_object']],
            retryOf: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        );
    }

    #[Test]
    public function actorScopeProtectsGetAndRecentWhileSystemCanSeeEveryRun(): void
    {
        $first = $this->createRun(ActorContext::user(7), request: ['owner' => 7]);
        $second = $this->createRun(ActorContext::user(8), request: ['owner' => 8]);

        self::assertNotNull($this->store->get($first, ActorContext::user(7)));
        self::assertNull($this->store->get($first, ActorContext::user(8)));
        self::assertNull($this->store->get($first, ActorContext::anonymous()));
        self::assertNotNull($this->store->get($first, ActorContext::system()));

        $userRuns = $this->store->recent(ActorContext::user(7));
        self::assertCount(1, $userRuns);
        self::assertSame($first, $userRuns[0]['id']);
        self::assertSame(['owner' => 7], $userRuns[0]['request_payload']);
        self::assertSame([], $this->store->recent(ActorContext::anonymous()));

        $systemRuns = $this->store->recent(ActorContext::system());
        self::assertCount(2, $systemRuns);
        self::assertEqualsCanonicalizing([$first, $second], array_column($systemRuns, 'id'));
    }

    #[Test]
    public function recentBoundsTheRequestedLimit(): void
    {
        for ($index = 0; $index < 101; ++$index) {
            $this->createRun(ActorContext::user(7), key: 'object:' . $index);
        }

        self::assertCount(100, $this->store->recent(ActorContext::user(7), 1_000));
        self::assertCount(1, $this->store->recent(ActorContext::user(7), 0));
    }

    #[Test]
    public function lifecycleTracksAttemptsResultsCountsAndTerminalFinish(): void
    {
        $runId = $this->store->create(OperationRunKind::Organize, ActorContext::system(), [
            ['key' => 'object:10', 'type' => 'data_object'],
            ['key' => 'object:11', 'type' => 'data_object'],
        ]);

        self::assertTrue($this->store->start($runId));
        self::assertFalse($this->store->start($runId));
        self::assertTrue($this->store->startItem($runId, 'object:10'));
        self::assertFalse($this->store->startItem($runId, 'object:10'));
        self::assertSame(OperationRunStatus::Running, $this->store->finish($runId));
        self::assertTrue($this->store->completeItem(
            $runId,
            'object:10',
            OperationRunItemStatus::Completed,
            ['operationCount' => 2],
        ));
        self::assertTrue($this->store->startItem($runId, 'object:11'));
        self::assertTrue($this->store->completeItem($runId, 'object:11', OperationRunItemStatus::Completed));
        self::assertSame(OperationRunStatus::Completed, $this->store->finish($runId));

        $run = $this->requiredRun($runId, ActorContext::system());
        self::assertSame(2, (int) $run['processed_count']);
        self::assertSame(2, (int) $run['succeeded_count']);
        self::assertSame(1, (int) $run['items'][0]['attempts']);
        self::assertSame(['operationCount' => 2], $run['items'][0]['result_payload']);
        self::assertNotNull($run['completed_at']);
        $completedAt = $run['completed_at'];

        self::assertSame(OperationRunStatus::Completed, $this->store->finish($runId));
        self::assertSame($completedAt, $this->requiredRun($runId, ActorContext::system())['completed_at']);
    }

    #[Test]
    public function terminalFinishReconcilesPersistedCountersFromItems(): void
    {
        $runId = $this->store->create(OperationRunKind::Organize, ActorContext::system(), [
            ['key' => 'object:10', 'type' => 'data_object'],
            ['key' => 'object:11', 'type' => 'data_object'],
        ]);
        self::assertTrue($this->store->completeItem($runId, 'object:10', OperationRunItemStatus::Completed));
        self::assertTrue($this->store->completeItem($runId, 'object:11', OperationRunItemStatus::Completed));
        self::assertSame(OperationRunStatus::Completed, $this->store->finish($runId));

        $this->connection->update(
            Installer::TABLE_OPERATION_RUN,
            [
                'total_count' => 0,
                'processed_count' => 0,
                'succeeded_count' => 0,
            ],
            ['id' => $runId],
        );

        self::assertSame(OperationRunStatus::Completed, $this->store->finish($runId));
        $run = $this->requiredRun($runId, ActorContext::system());
        self::assertSame(2, (int) $run['total_count']);
        self::assertSame(2, (int) $run['processed_count']);
        self::assertSame(2, (int) $run['succeeded_count']);
    }

    #[Test]
    public function itemCompletionIsIdempotentAndCannotUseANonTerminalStatus(): void
    {
        $runId = $this->createRun(ActorContext::system());

        self::assertTrue($this->store->completeItem(
            $runId,
            'object:1',
            OperationRunItemStatus::Completed,
            ['value' => 'first'],
        ));
        self::assertFalse($this->store->completeItem(
            $runId,
            'object:1',
            OperationRunItemStatus::Failed,
            ['value' => 'second'],
            'must not replace the first completion',
        ));

        $run = $this->requiredRun($runId, ActorContext::system());
        self::assertSame(1, (int) $run['processed_count']);
        self::assertSame(1, (int) $run['succeeded_count']);
        self::assertSame(0, (int) $run['failed_count']);
        self::assertSame(['value' => 'first'], $run['items'][0]['result_payload']);

        $this->expectException(\InvalidArgumentException::class);
        $this->store->completeItem($runId, 'object:1', OperationRunItemStatus::Running);
    }

    #[Test]
    public function cancellationFinalizesEveryOpenItemAndRefreshesCounts(): void
    {
        $runId = $this->store->create(OperationRunKind::Organize, ActorContext::user(7), [
            ['key' => 'object:10', 'type' => 'data_object', 'payload' => ['position' => 1]],
            ['key' => 'object:11', 'type' => 'data_object', 'payload' => ['position' => 2]],
            ['key' => 'object:12', 'type' => 'data_object', 'payload' => ['position' => 3]],
        ], ['trigger' => 'api']);
        self::assertTrue($this->store->start($runId));
        self::assertTrue($this->store->startItem($runId, 'object:10'));
        self::assertTrue($this->store->completeItem($runId, 'object:10', OperationRunItemStatus::Completed));

        self::assertFalse($this->store->requestCancellation($runId, ActorContext::user(8)));
        self::assertTrue($this->store->requestCancellation($runId, ActorContext::user(7)));
        self::assertFalse($this->store->requestCancellation($runId, ActorContext::user(7)));
        self::assertTrue($this->store->isCancellationRequested($runId));
        self::assertFalse($this->store->startItem($runId, 'object:12'));
        self::assertSame(OperationRunStatus::Cancelled, $this->store->finish($runId));

        $run = $this->requiredRun($runId, ActorContext::user(7));
        self::assertSame(OperationRunStatus::Cancelled->value, $run['status']);
        self::assertSame(3, (int) $run['processed_count']);
        self::assertSame(1, (int) $run['succeeded_count']);
        self::assertSame(0, (int) $run['skipped_count']);
        self::assertSame(0, (int) $run['failed_count']);
        self::assertSame([
            OperationRunItemStatus::Completed->value,
            OperationRunItemStatus::Cancelled->value,
            OperationRunItemStatus::Cancelled->value,
        ], array_column($run['items'], 'status'));
        self::assertFalse($this->store->isCancellationRequested($runId));
        self::assertSame(OperationRunStatus::Cancelled, $this->store->finish($runId));
    }

    #[Test]
    public function cancellationWaitsForRunningBatchItemsBeforeTerminalizing(): void
    {
        $runId = $this->store->create(OperationRunKind::Organize, ActorContext::user(7), [
            ['key' => 'object:10', 'type' => 'data_object'],
            ['key' => 'object:11', 'type' => 'data_object'],
        ]);
        self::assertTrue($this->store->start($runId));
        self::assertTrue($this->store->startItem($runId, 'object:10'));
        self::assertTrue($this->store->requestCancellation($runId, ActorContext::user(7)));

        self::assertSame(OperationRunStatus::CancelRequested, $this->store->finish($runId));

        $waiting = $this->requiredRun($runId, ActorContext::user(7));
        self::assertSame(OperationRunStatus::CancelRequested->value, $waiting['status']);
        self::assertSame(1, (int) $waiting['processed_count']);
        self::assertSame([
            OperationRunItemStatus::Running->value,
            OperationRunItemStatus::Cancelled->value,
        ], array_column($waiting['items'], 'status'));
        self::assertTrue($this->store->isCancellationRequested($runId));

        self::assertTrue($this->store->completeItem(
            $runId,
            'object:10',
            OperationRunItemStatus::Completed,
            ['operationCount' => 1],
        ));
        self::assertSame(OperationRunStatus::Cancelled, $this->store->finish($runId));

        $cancelled = $this->requiredRun($runId, ActorContext::user(7));
        self::assertSame(2, (int) $cancelled['processed_count']);
        self::assertSame(1, (int) $cancelled['succeeded_count']);
        self::assertSame([
            OperationRunItemStatus::Completed->value,
            OperationRunItemStatus::Cancelled->value,
        ], array_column($cancelled['items'], 'status'));
        self::assertSame(['operationCount' => 1], $cancelled['items'][0]['result_payload']);
    }

    #[Test]
    public function failureFinalizesOpenItemsWithoutOverwritingCompletedOnes(): void
    {
        $runId = $this->store->create(OperationRunKind::Organize, ActorContext::system(), [
            ['key' => 'object:10', 'type' => 'data_object'],
            ['key' => 'object:11', 'type' => 'data_object'],
        ]);
        self::assertTrue($this->store->start($runId));
        self::assertTrue($this->store->completeItem($runId, 'object:10', OperationRunItemStatus::Completed));

        $this->store->fail($runId, 'Worker failed.');

        $run = $this->requiredRun($runId, ActorContext::system());
        self::assertSame(OperationRunStatus::Failed->value, $run['status']);
        self::assertSame('Worker failed.', $run['error_message']);
        self::assertSame(2, (int) $run['processed_count']);
        self::assertSame(1, (int) $run['succeeded_count']);
        self::assertSame(1, (int) $run['failed_count']);
        self::assertSame(OperationRunItemStatus::Completed->value, $run['items'][0]['status']);
        self::assertSame(OperationRunItemStatus::Failed->value, $run['items'][1]['status']);
        self::assertSame('Worker failed.', $run['items'][1]['error_message']);

        $this->store->fail($runId, 'Must not replace the terminal failure.');
        self::assertSame('Worker failed.', $this->requiredRun($runId, ActorContext::system())['error_message']);
        self::assertSame(OperationRunStatus::Failed, $this->store->finish($runId));
    }

    #[Test]
    public function retryPreservesLineageRequestFingerprintsAndItemPayloads(): void
    {
        $runId = $this->store->create(OperationRunKind::Organize, ActorContext::user(7), [
            ['key' => 'object:10', 'type' => 'data_object', 'id' => 10, 'fingerprint' => 'version:1', 'payload' => ['trigger' => 'api', 'position' => 1]],
            ['key' => 'object:11', 'type' => 'data_object', 'id' => 11, 'fingerprint' => 'version:2', 'payload' => ['trigger' => 'api', 'position' => 2]],
            ['key' => 'object:12', 'type' => 'data_object', 'id' => 12, 'fingerprint' => 'version:3', 'payload' => ['trigger' => 'api', 'position' => 3]],
        ], ['trigger' => 'api', 'rule' => 'products']);
        self::assertTrue($this->store->completeItem($runId, 'object:10', OperationRunItemStatus::Completed));
        self::assertTrue($this->store->completeItem($runId, 'object:11', OperationRunItemStatus::Failed, error: 'temporary'));
        self::assertTrue($this->store->completeItem($runId, 'object:12', OperationRunItemStatus::Skipped, error: 'stale'));
        self::assertSame(OperationRunStatus::Partial, $this->store->finish($runId));
        self::assertNull($this->store->retry($runId, ActorContext::user(8)));

        $retryId = $this->store->retry($runId, ActorContext::user(7));
        self::assertNotNull($retryId);
        $retry = $this->requiredRun($retryId, ActorContext::user(7));
        self::assertSame(2, (int) $retry['attempt']);
        self::assertSame($runId, $retry['retry_of']);
        self::assertSame(['trigger' => 'api', 'rule' => 'products'], $retry['request_payload']);
        self::assertSame(['object:11'], array_column($retry['items'], 'item_key'));
        self::assertSame(['version:2'], array_column($retry['items'], 'fingerprint'));
        self::assertSame([
            ['trigger' => 'api', 'position' => 2],
        ], array_column($retry['items'], 'payload'));
        self::assertSame([OperationRunItemStatus::Queued->value], array_column($retry['items'], 'status'));
        self::assertSame([null], array_column($retry['items'], 'result_payload'));

        $this->store->fail($retryId, 'retry failed');
        $secondRetryId = $this->store->retry($retryId, ActorContext::user(7));
        self::assertNotNull($secondRetryId);
        $secondRetry = $this->requiredRun($secondRetryId, ActorContext::user(7));
        self::assertSame(3, (int) $secondRetry['attempt']);
        self::assertSame($retryId, $secondRetry['retry_of']);
        self::assertSame($retry['items'][0]['payload'], $secondRetry['items'][0]['payload']);
    }

    #[Test]
    public function retryCreatesAtMostOneDirectChild(): void
    {
        $runId = $this->createRun(ActorContext::user(7));
        self::assertTrue($this->store->completeItem($runId, 'object:1', OperationRunItemStatus::Failed));
        self::assertSame(OperationRunStatus::Partial, $this->store->finish($runId));

        $retryId = $this->store->retry($runId, ActorContext::user(7));
        self::assertNotNull($retryId);
        self::assertNull($this->store->retry($runId, ActorContext::user(7)));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . Installer::TABLE_OPERATION_RUN . ' WHERE retry_of = ?',
            [$runId],
        ));

        $this->expectException(\LogicException::class);
        $this->store->create(
            OperationRunKind::Organize,
            ActorContext::user(7),
            [['key' => 'object:1', 'type' => 'data_object', 'id' => 1]],
            retryOf: $runId,
        );
    }

    #[Test]
    public function cancellationAfterTheLastItemCompletesKeepsTheTruthfulOutcome(): void
    {
        $runId = $this->createRun(ActorContext::user(7));
        self::assertTrue($this->store->start($runId));
        self::assertTrue($this->store->startItem($runId, 'object:1'));
        self::assertTrue($this->store->requestCancellation($runId, ActorContext::user(7)));
        self::assertTrue($this->store->completeItem($runId, 'object:1', OperationRunItemStatus::Completed));

        self::assertSame(OperationRunStatus::Completed, $this->store->finish($runId));
        self::assertSame(OperationRunStatus::Completed->value, $this->requiredRun($runId, ActorContext::user(7))['status']);
    }

    #[Test]
    public function skippedItemsAreNotRetried(): void
    {
        $runId = $this->createRun(ActorContext::user(7));
        self::assertTrue($this->store->completeItem($runId, 'object:1', OperationRunItemStatus::Skipped));
        self::assertSame(OperationRunStatus::Partial, $this->store->finish($runId));

        self::assertNull($this->store->retry($runId, ActorContext::user(7)));
    }

    #[Test]
    public function systemRetryPreservesTheOriginalUserActor(): void
    {
        $runId = $this->createRun(ActorContext::user(7));
        self::assertTrue($this->store->completeItem($runId, 'object:1', OperationRunItemStatus::Failed));
        self::assertSame(OperationRunStatus::Partial, $this->store->finish($runId));

        $retryId = $this->store->retry($runId, ActorContext::system());
        self::assertNotNull($retryId);
        $retry = $this->requiredRun($retryId, ActorContext::user(7));
        self::assertSame('user', $retry['actor_type']);
        self::assertSame(7, (int) $retry['actor_user_id']);
    }

    #[Test]
    public function cancelledItemsAreRetryableWithTheirOriginalPayload(): void
    {
        $runId = $this->createRun(
            ActorContext::user(7),
            request: ['trigger' => 'api'],
            payload: ['trigger' => 'api', 'position' => 1],
        );
        self::assertTrue($this->store->requestCancellation($runId, ActorContext::user(7)));
        self::assertSame(OperationRunStatus::Cancelled, $this->store->finish($runId));

        $retryId = $this->store->retry($runId, ActorContext::user(7));
        self::assertNotNull($retryId);
        $retry = $this->requiredRun($retryId, ActorContext::user(7));
        self::assertSame($runId, $retry['retry_of']);
        self::assertSame(['trigger' => 'api', 'position' => 1], $retry['items'][0]['payload']);
    }

    #[Test]
    public function retryRejectsAnUnknownPersistedKindWithoutCreatingAChildRun(): void
    {
        $runId = $this->createRun(ActorContext::user(7));
        $this->store->fail($runId, 'The original run failed.');
        $this->connection->update(Installer::TABLE_OPERATION_RUN, ['kind' => 'unsupported'], ['id' => $runId]);

        self::assertNull($this->store->retry($runId, ActorContext::user(7)));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . Installer::TABLE_OPERATION_RUN));
    }

    /** @param array<string, mixed> $request */
    private function createRun(
        ActorContext $actor,
        array $request = [],
        string $key = 'object:1',
        array $payload = [],
    ): string {
        return $this->store->create(OperationRunKind::Organize, $actor, [[
            'key' => $key,
            'type' => 'data_object',
            'id' => 1,
            'payload' => $payload,
        ]], $request);
    }

    /** @return array<string, mixed> */
    private function requiredRun(string $runId, ActorContext $actor): array
    {
        $run = $this->store->get($runId, $actor);
        self::assertNotNull($run);

        return $run;
    }
}
