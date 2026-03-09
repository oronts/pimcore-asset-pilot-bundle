<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Filter;

use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Filter\AssetSizeFilter;
use Oronts\AssetPilotBundle\Model\Rule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\NullLogger;

#[CoversClass(AssetSizeFilter::class)]
class AssetSizeFilterTest extends TestCase
{
    private AssetSizeFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new AssetSizeFilter(new NullLogger());
    }

    private function createRule(?int $minSize, ?int $maxSize): Rule
    {
        return new Rule(
            name: 'test', class: 'Product', fields: [], condition: null,
            targetPath: '/test', strategy: MoveStrategy::Always, callback: null,
            priority: 10, enabled: true, filters: ['min_size' => $minSize, 'max_size' => $maxSize],
        );
    }

    private function createAsset(int $size): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getFileSize')->willReturn($size);
        $asset->method('getId')->willReturn(1);

        return $asset;
    }

    #[Test]
    public function acceptsWhenNoSizeLimits(): void
    {
        $rule = $this->createRule(null, null);
        $asset = $this->createAsset(5000);
        $object = $this->createMock(AbstractObject::class);

        self::assertTrue($this->filter->accept($asset, $object, $rule));
    }

    #[Test]
    public function rejectsWhenBelowMinSize(): void
    {
        $rule = $this->createRule(1000, null);
        $asset = $this->createAsset(500);
        $object = $this->createMock(AbstractObject::class);

        self::assertFalse($this->filter->accept($asset, $object, $rule));
    }

    #[Test]
    public function acceptsWhenAtMinSize(): void
    {
        $rule = $this->createRule(1000, null);
        $asset = $this->createAsset(1000);
        $object = $this->createMock(AbstractObject::class);

        self::assertTrue($this->filter->accept($asset, $object, $rule));
    }

    #[Test]
    public function rejectsWhenAboveMaxSize(): void
    {
        $rule = $this->createRule(null, 5000);
        $asset = $this->createAsset(6000);
        $object = $this->createMock(AbstractObject::class);

        self::assertFalse($this->filter->accept($asset, $object, $rule));
    }

    #[Test]
    public function acceptsWhenAtMaxSize(): void
    {
        $rule = $this->createRule(null, 5000);
        $asset = $this->createAsset(5000);
        $object = $this->createMock(AbstractObject::class);

        self::assertTrue($this->filter->accept($asset, $object, $rule));
    }

    #[Test]
    public function acceptsWithinRange(): void
    {
        $rule = $this->createRule(100, 10000);
        $asset = $this->createAsset(5000);
        $object = $this->createMock(AbstractObject::class);

        self::assertTrue($this->filter->accept($asset, $object, $rule));
    }

    #[Test]
    public function rejectsBelowRangeWithBothLimits(): void
    {
        $rule = $this->createRule(100, 10000);
        $asset = $this->createAsset(50);
        $object = $this->createMock(AbstractObject::class);

        self::assertFalse($this->filter->accept($asset, $object, $rule));
    }
}
