<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
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
    public function createPersistsRequestTargetsAndPayloads(): void
    {
        $runId = $this->store->create(
            'organize',
            ActorContext::user(7),
            [
                ['key' => 'object:10', 'type' => 'data_object', 'id' => 10, 'fingerprint' => 'version:4', 'payload' => ['trigger' => 'api']],
                ['key' => 'object:11', 'type' => 'data_object', 'id' => 11, 'payload' => ['trigger' => 'manual']],
            ],
            ['dryRun' => false, 'filters' => ['class' => 'Product']],
        );

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $runId);
        $run = $this->requiredRun($runId, ActorContext::user(7));
        self::assertSame('organize', $run['kind']);
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
    public function invalidItemRollsBackTheEntireCreate(): void
    {
        try {
            $this->store->create('organize', ActorContext::system(), [
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
            'organize',
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
        $runId = $this->store->create('organize', ActorContext::system(), [
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
        $runId = $this->store->create('organize', ActorContext::system(), [
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
        $runId = $this->store->create('organize', ActorContext::user(7), [
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
        $runId = $this->store->create('organize', ActorContext::user(7), [
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
        $runId = $this->store->create('organize', ActorContext::system(), [
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
        $runId = $this->store->create('organize', ActorContext::user(7), [
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

    /** @param array<string, mixed> $request */
    private function createRun(
        ActorContext $actor,
        array $request = [],
        string $key = 'object:1',
        array $payload = [],
    ): string {
        return $this->store->create('organize', $actor, [[
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
