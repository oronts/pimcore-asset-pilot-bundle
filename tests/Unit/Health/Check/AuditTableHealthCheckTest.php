<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Health\Check;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Health\Check\AuditTableHealthCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AuditTableHealthCheck::class)]
class AuditTableHealthCheckTest extends TestCase
{
    /** @param list<string> $missing */
    private function check(bool $current, array $missing = [], bool $throws = false): AuditTableHealthCheck
    {
        return new class ($this->createMock(Connection::class), $current, $missing, $throws) extends AuditTableHealthCheck {
            /** @param list<string> $missing */
            public function __construct(Connection $connection, private bool $current, private array $missing, private bool $throws)
            {
                parent::__construct($connection, new NullLogger());
            }

            protected function schemaStatus(): array
            {
                if ($this->throws) {
                    throw new \RuntimeException('schema introspection failed');
                }

                return ['current' => $this->current, 'missing' => $this->missing];
            }
        };
    }

    #[Test]
    public function okWhenTheOwnedSchemaIsCurrent(): void
    {
        self::assertSame(HealthStatus::Ok, $this->check(true)->run()->status);
    }

    #[Test]
    public function criticalWhenAnOwnedTableIsMissing(): void
    {
        $result = $this->check(false, ['asset_pilot_checksum'])->run();

        self::assertSame(HealthStatus::Critical, $result->status);
        self::assertSame(['asset_pilot_checksum'], $result->details['missing_tables']);
    }

    #[Test]
    public function criticalWhenTheStorageRunTableIsMissing(): void
    {
        $result = $this->check(false, ['asset_pilot_storage_run'])->run();

        self::assertSame(HealthStatus::Critical, $result->status);
        self::assertSame(['asset_pilot_storage_run'], $result->details['missing_tables']);
    }

    #[Test]
    public function criticalWhenAnOperationRunTableIsMissing(): void
    {
        $result = $this->check(false, ['asset_pilot_operation_run_item'])->run();

        self::assertSame(HealthStatus::Critical, $result->status);
        self::assertSame(['asset_pilot_operation_run_item'], $result->details['missing_tables']);
    }

    #[Test]
    public function criticalWhenTheOperationDeliveryTableIsMissing(): void
    {
        $result = $this->check(false, ['asset_pilot_operation_delivery'])->run();

        self::assertSame(HealthStatus::Critical, $result->status);
        self::assertSame(['asset_pilot_operation_delivery'], $result->details['missing_tables']);
    }

    #[Test]
    public function criticalWhenADependencyProjectionTableIsMissing(): void
    {
        $result = $this->check(false, ['asset_pilot_dependency_edge'])->run();

        self::assertSame(HealthStatus::Critical, $result->status);
        self::assertSame(['asset_pilot_dependency_edge'], $result->details['missing_tables']);
    }

    #[Test]
    public function criticalWhenColumnsOrIndexesDrift(): void
    {
        self::assertSame(HealthStatus::Critical, $this->check(false)->run()->status);
    }

    #[Test]
    public function warningWhenTheTableCannotBeVerified(): void
    {
        self::assertSame(HealthStatus::Warning, $this->check(false, throws: true)->run()->status);
    }
}
