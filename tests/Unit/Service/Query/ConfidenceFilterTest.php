<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service\Query;

use Oronts\AssetPilotBundle\Enum\ConfidenceLevel;
use Oronts\AssetPilotBundle\Service\Query\ConfidenceFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfidenceFilter::class)]
class ConfidenceFilterTest extends TestCase
{
    private const int NOW = 1_000_000_000;

    private function build(ConfidenceLevel $level): array
    {
        return ConfidenceFilter::build($level, 'asset_pilot_locked', 'asset_pilot_audit_log', self::NOW, 30, 90);
    }

    #[Test]
    public function protectedMatchesOnlyLockedAssets(): void
    {
        $spec = $this->build(ConfidenceLevel::Protected);

        self::assertCount(1, $spec['conditions']);
        self::assertStringContainsString('EXISTS', $spec['conditions'][0]);
        self::assertStringNotContainsString('NOT EXISTS', $spec['conditions'][0]);
        self::assertSame('asset_pilot_locked', $spec['params']['cf_lock_prop']);
    }

    #[Test]
    public function historicallyUsedExcludesLockedAndRequiresAuditHistory(): void
    {
        $spec = $this->build(ConfidenceLevel::HistoricallyUsed);

        self::assertStringStartsWith('NOT EXISTS', $spec['conditions'][0]);
        self::assertStringContainsString('EXISTS (SELECT 1 FROM asset_pilot_audit_log pal WHERE pal.asset_id = a.id', $spec['conditions'][1]);
    }

    #[Test]
    public function ageBucketsExcludeBothLockedAndAuditedAssets(): void
    {
        foreach ([ConfidenceLevel::RecentlyUploaded, ConfidenceLevel::ProbablyUnused, ConfidenceLevel::DefinitelyUnused] as $level) {
            $spec = $this->build($level);
            self::assertStringStartsWith('NOT EXISTS', $spec['conditions'][0], $level->value);
            self::assertStringStartsWith('NOT EXISTS', $spec['conditions'][1], $level->value);
            self::assertStringContainsString('asset_pilot_audit_log', $spec['conditions'][1], $level->value);
        }
    }

    #[Test]
    public function recentlyUploadedFiltersOnTheThirtyDayBoundary(): void
    {
        $spec = $this->build(ConfidenceLevel::RecentlyUploaded);

        self::assertContains('a.modificationDate > :conf_recent', $spec['conditions']);
        self::assertSame(self::NOW - (30 * 86400), $spec['params']['conf_recent']);
        self::assertArrayNotHasKey('conf_old', $spec['params']);
    }

    #[Test]
    public function probablyUnusedIsBoundedByBothThresholds(): void
    {
        $spec = $this->build(ConfidenceLevel::ProbablyUnused);

        self::assertContains('a.modificationDate > :conf_old AND a.modificationDate <= :conf_recent', $spec['conditions']);
        self::assertSame(self::NOW - (90 * 86400), $spec['params']['conf_old']);
        self::assertSame(self::NOW - (30 * 86400), $spec['params']['conf_recent']);
    }

    #[Test]
    public function definitelyUnusedIsEverythingOlderThanNinetyDays(): void
    {
        $spec = $this->build(ConfidenceLevel::DefinitelyUnused);

        self::assertContains('a.modificationDate <= :conf_old', $spec['conditions']);
        self::assertSame(self::NOW - (90 * 86400), $spec['params']['conf_old']);
        self::assertArrayNotHasKey('conf_recent', $spec['params']);
    }
}
