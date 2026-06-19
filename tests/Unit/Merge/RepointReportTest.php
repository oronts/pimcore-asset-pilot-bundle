<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Merge;

use Oronts\AssetPilotBundle\Merge\RepointReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RepointReport::class)]
class RepointReportTest extends TestCase
{
    #[Test]
    public function isFullyRepointedOnlyWhenNothingIsBlocked(): void
    {
        self::assertTrue((new RepointReport(5, 9, 3, []))->fullyRepointed);
        self::assertFalse((new RepointReport(5, 9, 3, ['document 12 references the copy']))->fullyRepointed);
    }
}
