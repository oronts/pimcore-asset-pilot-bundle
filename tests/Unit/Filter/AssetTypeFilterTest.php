<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Filter;

use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Filter\AssetTypeFilter;
use Oronts\AssetPilotBundle\Model\Rule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\NullLogger;

#[CoversClass(AssetTypeFilter::class)]
class AssetTypeFilterTest extends TestCase
{
    private AssetTypeFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new AssetTypeFilter(new NullLogger());
    }

    private function createRule(array $types): Rule
    {
        return new Rule(
            name: 'test', class: 'Product', fields: [], condition: null,
            targetPath: '/test', strategy: MoveStrategy::Always, callback: null,
            priority: 10, enabled: true, filters: ['types' => $types],
        );
    }

    private function createAsset(string $type): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getType')->willReturn($type);
        $asset->method('getId')->willReturn(1);

        return $asset;
    }

    #[Test]
    public function acceptsWhenNoTypesConfigured(): void
    {
        $rule = $this->createRule([]);
        $asset = $this->createAsset('image');
        $object = $this->createMock(AbstractObject::class);

        self::assertTrue($this->filter->accept($asset, $object, $rule));
    }

    #[Test]
    public function acceptsMatchingType(): void
    {
        $rule = $this->createRule(['image', 'video']);
        $asset = $this->createAsset('image');
        $object = $this->createMock(AbstractObject::class);

        self::assertTrue($this->filter->accept($asset, $object, $rule));
    }

    #[Test]
    public function rejectsNonMatchingType(): void
    {
        $rule = $this->createRule(['image']);
        $asset = $this->createAsset('document');
        $object = $this->createMock(AbstractObject::class);

        self::assertFalse($this->filter->accept($asset, $object, $rule));
    }

    #[Test]
    public function typesAreCaseSensitive(): void
    {
        $rule = $this->createRule(['Image']);
        $asset = $this->createAsset('image');
        $object = $this->createMock(AbstractObject::class);

        self::assertFalse($this->filter->accept($asset, $object, $rule));
    }

    #[Test]
    public function acceptsWhenFiltersKeyMissing(): void
    {
        $rule = new Rule(
            name: 'test', class: 'Product', fields: [], condition: null,
            targetPath: '/test', strategy: MoveStrategy::Always, callback: null,
            priority: 10, enabled: true, filters: [],
        );
        $asset = $this->createAsset('archive');
        $object = $this->createMock(AbstractObject::class);

        self::assertTrue($this->filter->accept($asset, $object, $rule));
    }
}
