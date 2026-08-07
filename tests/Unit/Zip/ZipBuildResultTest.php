<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Zip;

use Oronts\AssetPilotBundle\Zip\ZipBuildResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ZipBuildResult::class)]
final class ZipBuildResultTest extends TestCase
{
    #[Test]
    public function exposesTheCompleteArchiveContract(): void
    {
        $result = new ZipBuildResult('/tmp/assets.zip', 3, 2, 1);

        self::assertSame('/tmp/assets.zip', $result->path);
        self::assertSame(3, $result->requested);
        self::assertSame(2, $result->added);
        self::assertSame(1, $result->skipped);
        self::assertFalse($result->truncated);
        self::assertTrue($result->hasArchive());
    }

    #[Test]
    public function rejectsAnIncompleteNonTruncatedResult(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('account for every requested asset');

        new ZipBuildResult('/tmp/assets.zip', 3, 2, 0);
    }

    #[Test]
    public function rejectsAPathWithoutAddedAssets(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path is required exactly when assets were added');

        new ZipBuildResult('/tmp/assets.zip', 1, 0, 1);
    }

    #[Test]
    public function allowsATruncatedResultToReportUnprocessedAssets(): void
    {
        $result = new ZipBuildResult('/tmp/assets.zip', 5, 2, 1, true);

        self::assertTrue($result->truncated);
        self::assertTrue($result->hasArchive());
    }
}
