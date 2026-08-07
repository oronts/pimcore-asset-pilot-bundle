<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Service\AutomaticOrganizeIntentStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AutomaticOrganizeIntentStore::class)]
final class AutomaticOrganizeIntentStoreTest extends TestCase
{
    private string $databasePath;
    private Connection $firstConnection;
    private Connection $secondConnection;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'asset-pilot-intent-');
        if ($path === false) {
            throw new \RuntimeException('Unable to create a temporary intent-store database.');
        }

        $this->databasePath = $path;
        $params = ['driver' => 'pdo_sqlite', 'path' => $path];
        $this->firstConnection = DriverManager::getConnection($params);
        $this->secondConnection = DriverManager::getConnection($params);

        $schema = new Schema();
        Installer::ensureCurrentSchema($schema);
        $manager = $this->firstConnection->createSchemaManager();
        $manager->createTable($schema->getTable(Installer::TABLE_AUTOMATIC_ORGANIZE_INTENT));
        $manager->createTable($schema->getTable(Installer::TABLE_OPERATION_RUN));
    }

    protected function tearDown(): void
    {
        $this->firstConnection->close();
        $this->secondConnection->close();
        unlink($this->databasePath);
    }

    #[Test]
    public function bindsANewIntentForTheFirstSave(): void
    {
        $binding = $this->store()->bindOrCoalesce(42, 'run-a', TriggerType::ObjectSave, ActorContext::user(7));

        self::assertTrue($binding->isNew, 'the first save owns the intent');
        self::assertSame('run-a', $binding->runId);
    }

    #[Test]
    public function coalescesASecondSaveIntoTheSameRunAndMarksItDirty(): void
    {
        $store = $this->store();
        $store->bindOrCoalesce(42, 'run-a', TriggerType::ObjectSave, ActorContext::user(7));

        $second = $store->bindOrCoalesce(42, 'run-b', TriggerType::ObjectSave, ActorContext::user(7));

        self::assertFalse($second->isNew, 'a second save does not create a run');
        self::assertSame('run-a', $second->runId, 'it coalesces into the first run');
        self::assertSame(1, (int) $this->firstConnection->fetchOne(
            'SELECT dirty FROM ' . Installer::TABLE_AUTOMATIC_ORGANIZE_INTENT . ' WHERE id = 42',
        ));
    }

    #[Test]
    public function concurrentBindsAcrossConnectionsProduceExactlyOneNewOwner(): void
    {
        $first = (new AutomaticOrganizeIntentStore($this->firstConnection))
            ->bindOrCoalesce(42, 'run-a', TriggerType::ObjectSave, ActorContext::system());
        $second = (new AutomaticOrganizeIntentStore($this->secondConnection))
            ->bindOrCoalesce(42, 'run-b', TriggerType::ObjectSave, ActorContext::system());

        self::assertTrue($first->isNew);
        self::assertFalse($second->isNew, 'the second connection coalesces into the first run');
        self::assertSame('run-a', $second->runId);
    }

    #[Test]
    public function releaseRemovesACleanIntentAndReportsNothingPending(): void
    {
        $store = $this->store();
        $store->bindOrCoalesce(42, 'run-a', TriggerType::ObjectSave, ActorContext::user(7));

        self::assertFalse($store->releaseIfOwnedBy(42, 'run-a'), 'a clean intent needs no rotation');
        self::assertFalse($this->firstConnection->fetchOne(
            'SELECT run_id FROM ' . Installer::TABLE_AUTOMATIC_ORGANIZE_INTENT . ' WHERE id = 42',
        ));
    }

    #[Test]
    public function releaseReportsDirtyAndRemovesTheIntentWhenALaterSaveCoalesced(): void
    {
        $store = $this->store();
        $store->bindOrCoalesce(42, 'run-a', TriggerType::ObjectSave, ActorContext::user(7));
        $store->bindOrCoalesce(42, 'run-b', TriggerType::ObjectSave, ActorContext::user(7));

        self::assertTrue($store->releaseIfOwnedBy(42, 'run-a'), 'a coalesced save must be re-organized after release');
        self::assertFalse($this->firstConnection->fetchOne(
            'SELECT run_id FROM ' . Installer::TABLE_AUTOMATIC_ORGANIZE_INTENT . ' WHERE id = 42',
        ));
    }

    #[Test]
    public function releaseLeavesAnIntentOwnedByADifferentRunUntouched(): void
    {
        $store = $this->store();
        $store->bindOrCoalesce(42, 'run-a', TriggerType::ObjectSave, ActorContext::user(7));

        self::assertFalse($store->releaseIfOwnedBy(42, 'other-run'), 'a concurrent run must not drop a live intent');
        self::assertSame('run-a', $this->firstConnection->fetchOne(
            'SELECT run_id FROM ' . Installer::TABLE_AUTOMATIC_ORGANIZE_INTENT . ' WHERE id = 42',
        ));
    }

    #[Test]
    public function releaseIsANoOpForAnUnknownObject(): void
    {
        self::assertFalse($this->store()->releaseIfOwnedBy(999, 'run-a'));
    }

    #[Test]
    public function markDirtyIfPresentDurablySetsDirtyOnALiveIntent(): void
    {
        $store = $this->store();
        $store->bindOrCoalesce(42, 'run-a', TriggerType::ObjectSave, ActorContext::user(7));

        self::assertTrue($store->markDirtyIfPresent(42), 'a live intent is marked and reported present');
        self::assertSame(1, (int) $this->firstConnection->fetchOne(
            'SELECT dirty FROM ' . Installer::TABLE_AUTOMATIC_ORGANIZE_INTENT . ' WHERE id = 42',
        ), 'a save landing while the run is in flight is recorded durably so a crash before drain does not lose it');
        self::assertTrue($store->releaseIfOwnedBy(42, 'run-a'), 'the durable dirty flag drives a rotation');
    }

    #[Test]
    public function markDirtyIfPresentReportsFalseWithoutAnIntent(): void
    {
        self::assertFalse($this->store()->markDirtyIfPresent(999), 'no intent means the caller must record a fresh run');
        self::assertSame(0, (int) $this->firstConnection->fetchOne(
            'SELECT COUNT(*) FROM ' . Installer::TABLE_AUTOMATIC_ORGANIZE_INTENT . ' WHERE id = 999',
        ));
    }

    #[Test]
    public function staleIntentsReturnsIntentsWhoseRunIsTerminal(): void
    {
        $store = $this->store();
        $store->bindOrCoalesce(42, 'run-a', TriggerType::ObjectSave, ActorContext::user(7));
        $this->insertRun('run-a', OperationRunStatus::Completed);

        $stale = $store->staleIntents(10);

        self::assertCount(1, $stale);
        self::assertSame(42, $stale[0]->objectId);
    }

    #[Test]
    public function staleIntentsReturnsIntentsWhoseRunNoLongerExists(): void
    {
        $store = $this->store();
        $store->bindOrCoalesce(42, 'run-gone', TriggerType::ObjectSave, ActorContext::user(7));

        $stale = $store->staleIntents(10);

        self::assertCount(1, $stale, 'a retention-purged run leaves a reclaimable intent');
        self::assertSame('run-gone', $stale[0]->runId);
    }

    #[Test]
    public function staleIntentsExcludesIntentsWhoseRunIsStillActive(): void
    {
        $store = $this->store();
        $store->bindOrCoalesce(42, 'run-a', TriggerType::ObjectSave, ActorContext::user(7));
        $this->insertRun('run-a', OperationRunStatus::Running);

        self::assertSame([], $store->staleIntents(10), 'a live run keeps its intent bound');
    }

    #[Test]
    public function staleIntentsReconstructsTheTriggerAndActor(): void
    {
        $store = $this->store();
        $store->bindOrCoalesce(42, 'run-a', TriggerType::AssetUpload, ActorContext::user(7));
        $store->bindOrCoalesce(42, 'run-b', TriggerType::AssetUpload, ActorContext::user(7));
        $this->insertRun('run-a', OperationRunStatus::Failed);

        $stale = $store->staleIntents(10);

        self::assertSame(TriggerType::AssetUpload, $stale[0]->trigger);
        self::assertSame(ActorType::User, $stale[0]->actor->type);
        self::assertSame(7, $stale[0]->actor->userId);
        self::assertTrue($stale[0]->dirty, 'the coalesced save is still pending until rotation');
    }

    private function store(): AutomaticOrganizeIntentStore
    {
        return new AutomaticOrganizeIntentStore($this->firstConnection);
    }

    private function insertRun(string $runId, OperationRunStatus $status): void
    {
        $this->firstConnection->insert(Installer::TABLE_OPERATION_RUN, [
            'id' => $runId,
            'kind' => 'organize',
            'actor_type' => ActorType::System->value,
            'actor_user_id' => null,
            'status' => $status->value,
            'total_count' => 1,
            'processed_count' => 0,
            'succeeded_count' => 0,
            'skipped_count' => 0,
            'blocked_count' => 0,
            'failed_count' => 0,
            'attempt' => 1,
            'retry_of' => null,
            'request_payload' => '{}',
            'error_message' => null,
            'created_at' => '2026-08-02 10:00:00',
            'started_at' => null,
            'updated_at' => '2026-08-02 10:00:00',
            'completed_at' => null,
        ]);
    }
}
