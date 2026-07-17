<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Service\AssetDependencyTargetExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

#[CoversClass(AssetDependencyTargetExtractor::class)]
class AssetDependencyTargetExtractorTest extends TestCase
{
    #[Test]
    public function extractsDistinctNumericallySortedAssetIdsIgnoringNonAssets(): void
    {
        $source = $this->createMock(AbstractObject::class);
        $source->method('getId')->willReturn(500);
        $source->method('resolveDependencies')->willReturn([
            ['id' => 30, 'type' => 'asset'],
            ['id' => 5, 'type' => 'asset'],
            ['id' => 30, 'type' => 'asset'],
            ['id' => 9, 'type' => 'object'],
            ['id' => 200, 'type' => 'asset'],
            ['id' => 0, 'type' => 'asset'],
            ['type' => 'asset'],
        ]);

        self::assertSame([5, 30, 200], (new AssetDependencyTargetExtractor())->extract($source));
    }

    #[Test]
    public function excludesAnAssetsOwnSelfReference(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(77);
        $asset->method('resolveDependencies')->willReturn([
            ['id' => 77, 'type' => 'asset'],
            ['id' => 88, 'type' => 'asset'],
        ]);

        self::assertSame([88], (new AssetDependencyTargetExtractor())->extract($asset));
    }

    #[Test]
    public function returnsAnEmptyListWhenNoAssetDependenciesExist(): void
    {
        $source = $this->createMock(AbstractObject::class);
        $source->method('getId')->willReturn(1);
        $source->method('resolveDependencies')->willReturn([
            ['id' => 3, 'type' => 'object'],
            ['id' => 4, 'type' => 'document'],
        ]);

        self::assertSame([], (new AssetDependencyTargetExtractor())->extract($source));
    }
}
