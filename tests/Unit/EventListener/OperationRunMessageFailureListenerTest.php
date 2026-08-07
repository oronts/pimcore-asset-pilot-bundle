<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\EventListener;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
use Oronts\AssetPilotBundle\Enum\OperationRunKind;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\EventListener\OperationRunMessageFailureListener;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Message\BulkOrganizeMessage;
use Oronts\AssetPilotBundle\Message\OrganizeAssetsMessage;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Service\OperationRunStore;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

#[CoversClass(OperationRunMessageFailureListener::class)]
final class OperationRunMessageFailureListenerTest extends TestCase
{
    #[Test]
    public function leavesRunStateResumableWhenMessengerWillRetry(): void
    {
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::never())->method('completeItem');
        $runs->expects(self::never())->method('finish');
        $event = $this->event(new OrganizeAssetsMessage(42, TriggerType::Api, runId: 'run-1'));
        $event->setForRetry();

        (new OperationRunMessageFailureListener($runs, new NullLogger()))($event);
    }

    #[Test]
    public function failsTheExactSingleItemAfterRetriesAreExhausted(): void
    {
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())
            ->method('completeItem')
            ->with(
                'run-1',
                'object:42',
                OperationRunItemStatus::Failed,
                [],
                'Message handling exhausted all retries.',
            )->willReturn(true);
        $runs->expects(self::once())->method('finish')->with('run-1');

        (new OperationRunMessageFailureListener($runs, new NullLogger()))(
            $this->event(new OrganizeAssetsMessage(42, TriggerType::Api, runId: 'run-1')),
        );
    }

    #[Test]
    public function failsEveryExactBulkItemAfterRetriesAreExhausted(): void
    {
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $completed = [];
        $runs->expects(self::exactly(2))
            ->method('completeItem')
            ->willReturnCallback(static function (string $runId, string $itemKey, OperationRunItemStatus $status, array $result, ?string $error) use (&$completed): bool {
                self::assertSame('run-1', $runId);
                self::assertSame(OperationRunItemStatus::Failed, $status);
                self::assertSame([], $result);
                self::assertSame('Message handling exhausted all retries.', $error);
                $completed[] = $itemKey;

                return true;
            });
        $runs->expects(self::once())->method('finish')->with('run-1');

        (new OperationRunMessageFailureListener($runs, new NullLogger()))(
            $this->event(new BulkOrganizeMessage([42, 41, 42], TriggerType::Api, runId: 'run-1')),
        );
        sort($completed);
        self::assertSame(['object:41', 'object:42'], $completed);
    }
    #[Test]
    public function exhaustedBatchDoesNotFailSiblingBatchesInTheSameRun(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new Schema();
        Installer::ensureCurrentSchema($schema);
        $schemaManager = $connection->createSchemaManager();
        $schemaManager->createTable($schema->getTable(Installer::TABLE_OPERATION_RUN));
        $schemaManager->createTable($schema->getTable(Installer::TABLE_OPERATION_RUN_ITEM));

        $store = new OperationRunStore($connection);
        $runId = $store->create(
            OperationRunKind::Organize,
            ActorContext::system(),
            [
                ['key' => 'object:1', 'type' => 'data_object'],
                ['key' => 'object:2', 'type' => 'data_object'],
                ['key' => 'object:3', 'type' => 'data_object'],
                ['key' => 'object:4', 'type' => 'data_object'],
            ],
        );
        self::assertTrue($store->resume($runId));
        self::assertTrue($store->resumeItem($runId, 'object:3'));

        (new OperationRunMessageFailureListener($store, new NullLogger()))(
            $this->event(new BulkOrganizeMessage([1, 2], TriggerType::Api, runId: $runId)),
        );

        $run = $store->get($runId, ActorContext::system());
        self::assertNotNull($run);
        self::assertSame(OperationRunStatus::Running->value, $run['status']);
        self::assertSame(2, (int) $run['processed_count']);
        self::assertSame(2, (int) $run['failed_count']);
        self::assertSame([
            OperationRunItemStatus::Failed->value,
            OperationRunItemStatus::Failed->value,
            OperationRunItemStatus::Running->value,
            OperationRunItemStatus::Queued->value,
        ], array_column($run['items'], 'status'));
        $connection->close();
    }



    private function event(object $message): WorkerMessageFailedEvent
    {
        return new WorkerMessageFailedEvent(
            new Envelope($message),
            'async',
            new \RuntimeException('infrastructure unavailable'),
        );
    }
}
