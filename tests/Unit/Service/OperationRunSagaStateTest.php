<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

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
final class OperationRunSagaStateTest extends TestCase
{
    private function store(): OperationRunStore
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new Schema();
        Installer::ensureCurrentSchema($schema);
        $manager = $connection->createSchemaManager();
        $manager->createTable($schema->getTable(Installer::TABLE_OPERATION_RUN));
        $manager->createTable($schema->getTable(Installer::TABLE_OPERATION_RUN_ITEM));

        return new OperationRunStore($connection);
    }

    #[Test]
    public function runningItemCanResumeWithItsDurableState(): void
    {
        $store = $this->store();
        $runId = $store->create('duplicate-merge', ActorContext::system(), [[
            'key' => 'asset:9',
            'type' => 'duplicate_copy',
            'id' => 9,
            'state' => ['phase' => 'prepared'],
        ]]);
        self::assertTrue($store->resume($runId));
        self::assertTrue($store->resumeItem($runId, 'asset:9'));
        self::assertTrue($store->updateItemState($runId, 'asset:9', ['phase' => 'repointing', 'ownersDone' => 2]));

        self::assertTrue($store->resume($runId));
        self::assertTrue($store->resumeItem($runId, 'asset:9'));
        $run = $store->get($runId, ActorContext::system());

        self::assertNotNull($run);
        self::assertSame(['phase' => 'repointing', 'ownersDone' => 2], $run['items'][0]['state_payload']);
        self::assertSame(2, (int) $run['items'][0]['attempts']);
    }

    #[Test]
    public function allBlockedItemsProduceBlockedRunAndRetryPreservesSagaState(): void
    {
        $store = $this->store();
        $runId = $store->create('duplicate-merge', ActorContext::system(), [[
            'key' => 'asset:9',
            'type' => 'duplicate_copy',
            'id' => 9,
            'payload' => ['strategy' => 'quarantine'],
            'state' => ['phase' => 'prepared'],
        ]]);
        self::assertTrue($store->completeItem(
            $runId,
            'asset:9',
            OperationRunItemStatus::Blocked,
            ['reason' => 'referrer changed'],
        ));

        self::assertSame(OperationRunStatus::Blocked, $store->finish($runId));
        $blocked = $store->get($runId, ActorContext::system());
        self::assertNotNull($blocked);
        self::assertSame(1, (int) $blocked['blocked_count']);
        self::assertSame(0, (int) $blocked['skipped_count']);

        $retryId = $store->retry($runId, ActorContext::system());
        self::assertNotNull($retryId);
        $retry = $store->get($retryId, ActorContext::system());
        self::assertNotNull($retry);
        self::assertSame(['phase' => 'prepared'], $retry['items'][0]['state_payload']);
        self::assertSame(['strategy' => 'quarantine'], $retry['items'][0]['payload']);
    }
}
