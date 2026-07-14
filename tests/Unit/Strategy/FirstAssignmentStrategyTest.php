<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Strategy;

use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Strategy\FirstAssignmentStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

#[CoversClass(FirstAssignmentStrategy::class)]
class FirstAssignmentStrategyTest extends TestCase
{
    private function createRule(): Rule
    {
        return new Rule(
            name: 'test', class: 'Product', fields: [], condition: null,
            targetPath: '/test', strategy: MoveStrategy::FirstAssignment, callback: null,
            priority: 10, enabled: true, filters: [],
        );
    }

    #[Test]
    public function allowsMoveWhenAssignmentPropertyIsMissing(): void
    {
        $strategy = new FirstAssignmentStrategy();

        $asset = $this->createMock(Asset::class);
        $asset->method('getProperty')->with(FirstAssignmentStrategy::ASSIGNMENT_PROPERTY)->willReturn(null);
        $object = $this->createMock(AbstractObject::class);

        self::assertTrue($strategy->resolve($asset, $object, $this->createRule()));
    }

    #[Test]
    public function rejectsMoveWhenAssignmentPropertyIsSet(): void
    {
        $strategy = new FirstAssignmentStrategy();

        $asset = $this->createMock(Asset::class);
        $asset->method('getProperty')->with(FirstAssignmentStrategy::ASSIGNMENT_PROPERTY)->willReturn(true);
        $object = $this->createMock(AbstractObject::class);

        self::assertFalse($strategy->resolve($asset, $object, $this->createRule()));
    }

    #[Test]
    public function supportsFirstAssignmentStrategy(): void
    {
        $strategy = new FirstAssignmentStrategy();

        self::assertTrue($strategy->supports(MoveStrategy::FirstAssignment));
    }

    #[Test]
    public function doesNotSupportAlwaysStrategy(): void
    {
        $strategy = new FirstAssignmentStrategy();

        self::assertFalse($strategy->supports(MoveStrategy::Always));
    }

}
