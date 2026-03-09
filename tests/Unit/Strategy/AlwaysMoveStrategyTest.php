<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Strategy;

use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Strategy\AlwaysMoveStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\NullLogger;

#[CoversClass(AlwaysMoveStrategy::class)]
class AlwaysMoveStrategyTest extends TestCase
{
    private AlwaysMoveStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new AlwaysMoveStrategy(new NullLogger());
    }

    #[Test]
    public function resolveAlwaysReturnsTrue(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(1);
        $object = $this->createMock(AbstractObject::class);
        $rule = new Rule(
            name: 'test', class: 'Product', fields: [], condition: null,
            targetPath: '/test', strategy: MoveStrategy::Always, callback: null,
            priority: 10, enabled: true, filters: [],
        );

        self::assertTrue($this->strategy->resolve($asset, $object, $rule));
    }

    #[Test]
    public function supportsAlwaysStrategy(): void
    {
        self::assertTrue($this->strategy->supports(MoveStrategy::Always));
    }

    #[Test]
    public function doesNotSupportFirstAssignment(): void
    {
        self::assertFalse($this->strategy->supports(MoveStrategy::FirstAssignment));
    }

    #[Test]
    public function doesNotSupportCallback(): void
    {
        self::assertFalse($this->strategy->supports(MoveStrategy::Callback));
    }
}
