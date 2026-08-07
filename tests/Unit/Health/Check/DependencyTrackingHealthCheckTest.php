<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Health\Check;

use Oronts\AssetPilotBundle\Enum\DependencyProjectionState;
use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Health\Check\DependencyTrackingHealthCheck;
use Oronts\AssetPilotBundle\Model\DependencyProjectionStatus;
use Oronts\AssetPilotBundle\Service\DependencyProjectionFreshnessInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DependencyTrackingHealthCheck::class)]
class DependencyTrackingHealthCheckTest extends TestCase
{
    #[Test]
    public function isCriticalWhenDependencyTrackingIsDisabled(): void
    {
        $result = $this->check(false)->run();

        self::assertSame(HealthStatus::Critical, $result->status);
        self::assertFalse($result->details['enabled']);
    }

    #[Test]
    public function isOkWhenProjectionIsReadyAndClean(): void
    {
        $result = $this->check(true, $this->projectionStatus(DependencyProjectionState::Ready))->run();

        self::assertSame(HealthStatus::Ok, $result->status);
        self::assertTrue($result->details['enabled']);
        self::assertTrue($result->details['freshness_verified']);
        self::assertSame(23, $result->details['source_count']);
    }

    #[Test]
    public function warnsWhenProjectionIsBuildingOrDirty(): void
    {
        $result = $this->check(true, $this->projectionStatus(DependencyProjectionState::Building, 2))->run();

        self::assertSame(HealthStatus::Warning, $result->status);
        self::assertFalse($result->details['freshness_verified']);
        self::assertSame(2, $result->details['dirty_sources']);
    }

    #[Test]
    public function isCriticalWhenProjectionRebuildFailed(): void
    {
        $result = $this->check(true, $this->projectionStatus(DependencyProjectionState::Failed))->run();

        self::assertSame(HealthStatus::Critical, $result->status);
        self::assertSame('failed', $result->details['projection_state']);
    }

    private function check(bool $enabled, ?DependencyProjectionStatus $status = null): DependencyTrackingHealthCheck
    {
        $freshness = $this->createMock(DependencyProjectionFreshnessInterface::class);
        if ($status !== null) {
            $freshness->method('status')->willReturn($status);
        }

        return new class ($enabled, $freshness) extends DependencyTrackingHealthCheck {
            public function __construct(private bool $enabled, DependencyProjectionFreshnessInterface $freshness)
            {
                parent::__construct($freshness);
            }

            protected function systemConfiguration(): array
            {
                return ['dependency' => ['enabled' => $this->enabled]];
            }
        };
    }

    private function projectionStatus(DependencyProjectionState $state, int $dirty = 0): DependencyProjectionStatus
    {
        return new DependencyProjectionStatus($state, 3, $dirty, 23, 51, 'asset', 99, null, null, $state === DependencyProjectionState::Failed ? 'failed' : null);
    }
}
