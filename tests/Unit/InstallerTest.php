<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit;

use Oronts\AssetPilotBundle\Installer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
        };
    }

    #[Test]
    public function declaresEveryAuditColumn(): void
    {
        $names = array_map(static fn (array $c): string => $c[0], $this->schema()->cols());

        self::assertSame([
            'id', 'asset_id', 'asset_path_from', 'asset_path_to', 'object_id', 'object_class',
            'rule_name', 'trigger_type', 'status', 'error_message', 'duration_ms', 'user_id', 'created_at',
        ], $names);
    }

    #[Test]
    public function declaresTheCompositeIndexes(): void
    {
        $indexes = $this->schema()->idx();

        self::assertSame(['asset_id', 'status'], $indexes['idx_audit_asset_status']);
        self::assertSame(['rule_name', 'status', 'created_at'], $indexes['idx_audit_rule_status_created']);
    }
}
