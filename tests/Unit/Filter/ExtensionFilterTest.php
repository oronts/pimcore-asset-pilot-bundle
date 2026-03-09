<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Filter;

use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Filter\ExtensionFilter;
use Oronts\AssetPilotBundle\Model\Rule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\NullLogger;

#[CoversClass(ExtensionFilter::class)]
class ExtensionFilterTest extends TestCase
{
    private ExtensionFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new ExtensionFilter(new NullLogger());
    }

    private function createRule(array $extensions): Rule
    {
        return new Rule(
            name: 'test', class: 'Product', fields: [], condition: null,
            targetPath: '/test', strategy: MoveStrategy::Always, callback: null,
            priority: 10, enabled: true, filters: ['extensions' => $extensions],
        );
    }

    private function createAsset(string $filename): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getFilename')->willReturn($filename);
        $asset->method('getId')->willReturn(1);

        return $asset;
    }

    #[Test]
    public function acceptsWhenNoExtensionsConfigured(): void
    {
        $rule = $this->createRule([]);
        $asset = $this->createAsset('photo.png');
        $object = $this->createMock(AbstractObject::class);

        self::assertTrue($this->filter->accept($asset, $object, $rule));
    }

    #[Test]
    public function acceptsMatchingExtension(): void
    {
        $rule = $this->createRule(['png', 'jpg']);
        $asset = $this->createAsset('photo.png');
        $object = $this->createMock(AbstractObject::class);

        self::assertTrue($this->filter->accept($asset, $object, $rule));
    }

    #[Test]
    public function rejectsNonMatchingExtension(): void
    {
        $rule = $this->createRule(['png', 'jpg']);
        $asset = $this->createAsset('doc.pdf');
        $object = $this->createMock(AbstractObject::class);

        self::assertFalse($this->filter->accept($asset, $object, $rule));
    }

    #[Test]
    public function extensionComparisonIsCaseInsensitive(): void
    {
        $rule = $this->createRule(['PNG', 'JPG']);
        $asset = $this->createAsset('photo.png');
        $object = $this->createMock(AbstractObject::class);

        self::assertTrue($this->filter->accept($asset, $object, $rule));
    }
}
