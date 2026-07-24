<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Oronts\AssetPilotBundle\Installer;
use Oronts\AssetPilotBundle\Service\DbalApplyPlanClaimStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DbalApplyPlanClaimStore::class)]
final class DbalApplyPlanClaimStoreTest extends TestCase
{
    private string $databasePath;
    private Connection $firstConnection;
    private Connection $secondConnection;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'asset-pilot-claims-');
        if ($path === false) {
            throw new \RuntimeException('Unable to create a temporary claim-store database.');
        }

        $this->databasePath = $path;
        $params = ['driver' => 'pdo_sqlite', 'path' => $path];
        $this->firstConnection = DriverManager::getConnection($params);
        $this->secondConnection = DriverManager::getConnection($params);

        $schema = new Schema();
        Installer::ensureCurrentSchema($schema);
        $this->firstConnection->createSchemaManager()->createTable(
            $schema->getTable(Installer::TABLE_APPLY_PLAN_CLAIM),
        );
    }

    protected function tearDown(): void
    {
        $this->firstConnection->close();
        $this->secondConnection->close();
        unlink($this->databasePath);
    }

    #[Test]
    public function separateConnectionsCanClaimATokenOnlyOnce(): void
    {
        $claimId = hash('sha256', 'signed-plan');
        $claimedAt = new \DateTimeImmutable('2026-07-16 10:00:00 UTC');
        $expiresAt = $claimedAt->modify('+5 minutes');

        self::assertTrue((new DbalApplyPlanClaimStore($this->firstConnection))->claim($claimId, $claimedAt, $expiresAt));
        self::assertFalse((new DbalApplyPlanClaimStore($this->secondConnection))->claim($claimId, $claimedAt, $expiresAt));
        self::assertSame(1, (int) $this->firstConnection->fetchOne(
            'SELECT COUNT(*) FROM ' . Installer::TABLE_APPLY_PLAN_CLAIM . ' WHERE id = ?',
            [$claimId],
        ));
    }

    #[Test]
    public function claimingPurgesExpiredRowsWithoutRemovingLiveClaims(): void
    {
        $expiredId = hash('sha256', 'expired');
        $liveId = hash('sha256', 'live');
        $now = new \DateTimeImmutable('2026-07-16 10:00:00 UTC');
        $this->firstConnection->insert(Installer::TABLE_APPLY_PLAN_CLAIM, [
            'id' => $expiredId,
            'claimed_at' => '2026-07-16 09:50:00',
            'expires_at' => '2026-07-16 10:00:00',
        ]);

        self::assertTrue((new DbalApplyPlanClaimStore($this->firstConnection))->claim(
            $liveId,
            $now,
            $now->modify('+5 minutes'),
        ));
        self::assertSame([$liveId], $this->firstConnection->fetchFirstColumn(
            'SELECT id FROM ' . Installer::TABLE_APPLY_PLAN_CLAIM . ' ORDER BY id',
        ));
    }

    #[Test]
    public function cleanupIsBoundedPerClaim(): void
    {
        $now = new \DateTimeImmutable('2026-07-16 10:00:00 UTC');
        foreach ([
            hash('sha256', 'expired-oldest') => '2026-07-16 09:00:00',
            hash('sha256', 'expired-newest') => '2026-07-16 09:30:00',
        ] as $id => $expiresAt) {
            $this->firstConnection->insert(Installer::TABLE_APPLY_PLAN_CLAIM, [
                'id' => $id,
                'claimed_at' => '2026-07-16 08:00:00',
                'expires_at' => $expiresAt,
            ]);
        }
        $liveId = hash('sha256', 'live');

        self::assertTrue((new DbalApplyPlanClaimStore($this->firstConnection, 1))->claim(
            $liveId,
            $now,
            $now->modify('+5 minutes'),
        ));
        self::assertSame(
            [hash('sha256', 'expired-newest'), $liveId],
            $this->firstConnection->fetchFirstColumn(
                'SELECT id FROM ' . Installer::TABLE_APPLY_PLAN_CLAIM . ' ORDER BY expires_at, id',
            ),
        );
    }

    #[Test]
    public function malformedClaimIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $now = new \DateTimeImmutable('2026-07-16 10:00:00 UTC');
        (new DbalApplyPlanClaimStore($this->firstConnection))->claim(
            'not-a-hash',
            $now,
            $now->modify('+5 minutes'),
        );
    }

    #[Test]
    public function nonPositiveLifetimeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $now = new \DateTimeImmutable('2026-07-16 10:00:00 UTC');
        (new DbalApplyPlanClaimStore($this->firstConnection))->claim(hash('sha256', 'plan'), $now, $now);
    }

    #[Test]
    public function nonPositiveCleanupBatchIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DbalApplyPlanClaimStore($this->firstConnection, 0);
    }
}
