<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit;

use Oronts\AssetPilotBundle\OrontsAssetPilotBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrontsAssetPilotBundle::class)]
final class OrontsAssetPilotBundleTest extends TestCase
{
    #[Test]
    public function composerPackageNameRemainsPublicForPimcoreMetadata(): void
    {
        $bundle = new OrontsAssetPilotBundle();

        self::assertSame('oronts/asset-pilot-bundle', $bundle->getComposerPackageName());
    }
}
