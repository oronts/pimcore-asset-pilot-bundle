<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Model;

use Oronts\AssetPilotBundle\Model\AssetFieldInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;

#[CoversClass(AssetFieldInfo::class)]
class AssetFieldInfoTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $asset1 = $this->createMock(Asset::class);
        $asset2 = $this->createMock(Asset::class);

        $info = new AssetFieldInfo(
            fieldName: 'productImages',
            locale: 'en_US',
            fieldType: 'manyToManyRelation',
            assets: [$asset1, $asset2],
        );

        self::assertSame('productImages', $info->fieldName);
        self::assertSame('en_US', $info->locale);
        self::assertSame('manyToManyRelation', $info->fieldType);
        self::assertCount(2, $info->assets);
        self::assertSame($asset1, $info->assets[0]);
        self::assertSame($asset2, $info->assets[1]);
    }

    #[Test]
    public function localeCanBeNull(): void
    {
        $info = new AssetFieldInfo(
            fieldName: 'mainImage',
            locale: null,
            fieldType: 'manyToOneRelation',
            assets: [],
        );

        self::assertNull($info->locale);
    }

    #[Test]
    public function acceptsEmptyAssets(): void
    {
        $info = new AssetFieldInfo(
            fieldName: 'gallery',
            locale: null,
            fieldType: 'advancedManyToManyRelation',
            assets: [],
        );

        self::assertSame([], $info->assets);
    }
}
