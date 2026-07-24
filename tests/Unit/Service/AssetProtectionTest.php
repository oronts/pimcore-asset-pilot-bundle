<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Service\AssetProtection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;

#[CoversClass(AssetProtection::class)]
class AssetProtectionTest extends TestCase
{
    #[Test]
    public function notLockedWhenPropertyAbsent(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('hasProperty')->willReturn(false);

        self::assertFalse(AssetProtection::isLocked($asset, AssetProtection::DEFAULT_LOCK_PROPERTY));
    }

    #[Test]
    public function notLockedWhenPropertyPresentButFalsy(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('hasProperty')->willReturn(true);
        $asset->method('getProperty')->willReturn(false);

        self::assertFalse(AssetProtection::isLocked($asset, AssetProtection::DEFAULT_LOCK_PROPERTY));
    }

    #[Test]
    public function lockedWhenPropertyPresentAndTruthy(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('hasProperty')->with(AssetProtection::DEFAULT_LOCK_PROPERTY)->willReturn(true);
        $asset->method('getProperty')->with(AssetProtection::DEFAULT_LOCK_PROPERTY)->willReturn(true);

        self::assertTrue(AssetProtection::isLocked($asset, AssetProtection::DEFAULT_LOCK_PROPERTY));
    }
}
