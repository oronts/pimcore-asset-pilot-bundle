<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Filter;

use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Filter\AssetFilterInterface;
use Oronts\AssetPilotBundle\Filter\CompositeFilter;
use Oronts\AssetPilotBundle\Model\Rule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\NullLogger;

#[CoversClass(CompositeFilter::class)]
class CompositeFilterTest extends TestCase
{
    private function createRule(): Rule
    {
        return new Rule(
            name: 'test', class: 'Product', fields: [], condition: null,
            targetPath: '/test', strategy: MoveStrategy::Always, callback: null,
            priority: 10, enabled: true, filters: [],
        );
    }

    #[Test]
    public function acceptsWhenAllFiltersPass(): void
    {
        $filter1 = $this->createMock(AssetFilterInterface::class);
        $filter1->method('accept')->willReturn(true);

        $filter2 = $this->createMock(AssetFilterInterface::class);
        $filter2->method('accept')->willReturn(true);

        $composite = new CompositeFilter([$filter1, $filter2], new NullLogger());

        $asset = $this->createMock(Asset::class);
        $object = $this->createMock(AbstractObject::class);

        self::assertTrue($composite->accept($asset, $object, $this->createRule()));
    }

    #[Test]
    public function rejectsWhenAnyFilterFails(): void
    {
        $filter1 = $this->createMock(AssetFilterInterface::class);
        $filter1->method('accept')->willReturn(true);

        $filter2 = $this->createMock(AssetFilterInterface::class);
        $filter2->method('accept')->willReturn(false);

        $composite = new CompositeFilter([$filter1, $filter2], new NullLogger());

        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(1);
        $object = $this->createMock(AbstractObject::class);

        self::assertFalse($composite->accept($asset, $object, $this->createRule()));
    }

    #[Test]
    public function acceptsWithNoFilters(): void
    {
        $composite = new CompositeFilter([], new NullLogger());

        $asset = $this->createMock(Asset::class);
        $object = $this->createMock(AbstractObject::class);

        self::assertTrue($composite->accept($asset, $object, $this->createRule()));
    }

    #[Test]
    public function shortCircuitsOnFirstRejection(): void
    {
        $filter1 = $this->createMock(AssetFilterInterface::class);
        $filter1->method('accept')->willReturn(false);

        $filter2 = $this->createMock(AssetFilterInterface::class);
        $filter2->expects(self::never())->method('accept');

        $composite = new CompositeFilter([$filter1, $filter2], new NullLogger());

        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(1);
        $object = $this->createMock(AbstractObject::class);

        self::assertFalse($composite->accept($asset, $object, $this->createRule()));
    }

    #[Test]
    public function excludesSelfFromFilters(): void
    {
        $filter1 = $this->createMock(AssetFilterInterface::class);
        $filter1->method('accept')->willReturn(true);

        $composite = new CompositeFilter([$filter1], new NullLogger());

        $asset = $this->createMock(Asset::class);
        $object = $this->createMock(AbstractObject::class);

        self::assertTrue($composite->accept($asset, $object, $this->createRule()));
    }
}
