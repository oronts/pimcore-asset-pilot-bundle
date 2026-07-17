<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Installer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\StaticResolverBundle\Lib\CacheResolverInterface;
use Pimcore\Bundle\StudioBackendBundle\Util\Constant\CacheKeys;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;

#[CoversClass(Installer::class)]
class InstallerTest extends TestCase
{
    /** Exposes the protected static schema definitions without constructing the real installer. */
    private function schema(): Installer
    {
        return new class () extends Installer {
            public function __construct() {}

            /** @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
            public function cols(): array
            {
                return self::auditColumns();
            }

            /** @return array<string, list<string>> */
            public function idx(): array
            {
                return self::auditIndexes();
            }

            /** @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
            public function deliveryCols(): array
            {
                return self::operationDeliveryColumns();
            }

            /** @return array<string, list<string>> */
            public function deliveryIdx(): array
            {
                return self::operationDeliveryIndexes();
            }

            /** @return array<string, list<string>> */
            public function deliveryUniqueIdx(): array
            {
                return self::operationDeliveryUniqueIndexes();
            }

            /** @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
            public function claimCols(): array
            {
                return self::applyPlanClaimColumns();
            }

            /** @return array<string, list<string>> */
            public function claimIdx(): array
            {
                return self::applyPlanClaimIndexes();
            }
        };
    }

    #[Test]
    public function declaresEveryAuditColumn(): void
    {
        $names = array_map(static fn (array $c): string => $c[0], $this->schema()->cols());

        self::assertSame([
            'id', 'asset_id', 'asset_path_from', 'asset_path_to', 'object_id', 'object_class',
            'rule_name', 'trigger_type', 'status', 'error_message', 'durable_observer_failures', 'duration_ms', 'user_id', 'created_at',
            'operation_kind', 'actor_type', 'parent_audit_id', 'intent_payload', 'schema_version',
            'updated_at', 'committed_at',
        ], $names);
    }

    #[Test]
    public function supportsFullPimcorePathsInTheAuditLog(): void
    {
        $columns = array_column($this->schema()->cols(), null, 0);

        self::assertSame(765, $columns['asset_path_from'][2]['length']);
        self::assertSame(765, $columns['asset_path_to'][2]['length']);
    }

    #[Test]
    public function declaresTheCompositeIndexes(): void
    {
        $indexes = $this->schema()->idx();

        self::assertSame(['asset_id', 'status'], $indexes['idx_audit_asset_status']);
        self::assertSame(['rule_name', 'status', 'created_at'], $indexes['idx_audit_rule_status_created']);
        // Back the filtered-and-ordered dashboard / recent / failed-object queries.
        self::assertSame(['status', 'created_at'], $indexes['idx_audit_status_created']);
        self::assertSame(['object_class', 'created_at'], $indexes['idx_audit_class_created']);
        self::assertSame(['status', 'updated_at'], $indexes['idx_audit_recovery']);
        self::assertSame(['parent_audit_id'], $indexes['idx_audit_parent']);
        // Superseded by the (status, created_at) left prefix; must not be re-declared.
        self::assertArrayNotHasKey('idx_audit_status', $indexes);
    }

    #[Test]
    public function declaresTheDurableDeliveryOutbox(): void
    {
        $schema = $this->schema();
        $names = array_map(static fn (array $column): string => $column[0], $schema->deliveryCols());

        self::assertSame([
            'id', 'operation_id', 'delivery_key', 'observer_id', 'outcome', 'intent_payload', 'payload',
            'status', 'attempts', 'available_at', 'lock_token', 'locked_until', 'last_error', 'created_at',
            'updated_at', 'delivered_at', 'audit_reconciled_at',
        ], $names);
        self::assertSame(['status', 'available_at'], $schema->deliveryIdx()['idx_operation_delivery_due']);
        self::assertSame(['status', 'locked_until'], $schema->deliveryIdx()['idx_operation_delivery_reclaim']);
        self::assertSame(['operation_id', 'status'], $schema->deliveryIdx()['idx_operation_delivery_operation']);
        self::assertSame(['status', 'audit_reconciled_at'], $schema->deliveryIdx()['idx_operation_delivery_audit_reconcile']);
        self::assertSame(['operation_id', 'observer_id', 'delivery_key'], $schema->deliveryUniqueIdx()['uniq_operation_delivery_key']);
    }

    #[Test]
    public function declaresTheApplyPlanClaimStore(): void
    {
        $schema = $this->schema();
        $columns = array_column($schema->claimCols(), null, 0);

        self::assertSame(64, $columns['id'][2]['length']);
        self::assertSame('datetime', $columns['claimed_at'][1]);
        self::assertSame('datetime', $columns['expires_at'][1]);
        self::assertSame(['expires_at'], $schema->claimIdx()['idx_apply_plan_claim_expires']);
    }

    #[Test]
    public function invalidatesStudioPermissionCache(): void
    {
        $cacheResolver = $this->createMock(CacheResolverInterface::class);
        $cacheResolver->expects(self::once())
            ->method('remove')
            ->with(CacheKeys::USER_PERMISSIONS->value);
        $installer = new class (
            $this->createStub(BundleInterface::class),
            $this->createStub(Connection::class),
            $cacheResolver,
        ) extends Installer {
            public function invalidatePermissions(): void
            {
                $this->invalidatePermissionCache();
            }
        };

        $installer->invalidatePermissions();
    }
}
