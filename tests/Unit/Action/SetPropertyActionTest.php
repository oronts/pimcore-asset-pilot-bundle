<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Action;

use Oronts\AssetPilotBundle\Action\SetPropertyAction;
use Oronts\AssetPilotBundle\Service\AssetPropertyService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

#[CoversClass(SetPropertyAction::class)]
class SetPropertyActionTest extends TestCase
{
    private function asset(): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(42);
        $asset->method('getRealFullPath')->willReturn('/Products/a.jpg');

        return $asset;
    }

    #[Test]
    public function typeIsSetProperty(): void
    {
        $action = new SetPropertyAction($this->createMock(AssetPropertyService::class));

        self::assertSame('set_property', $action->getType());
    }

    #[Test]
    public function writesAStaticValueThroughThePropertyService(): void
    {
        $service = $this->createMock(AssetPropertyService::class);
        $service->expects(self::once())->method('setProperty')->with(42, '/Products/a.jpg', 'cdn_ready', 'text', 'yes');

        (new SetPropertyAction($service))->apply($this->asset(), $this->createMock(AbstractObject::class), [
            'type' => 'set_property',
            'name' => 'cdn_ready',
            'value' => 'yes',
        ]);
    }

    #[Test]
    public function castsABooleanValueToDbBool(): void
    {
        $service = $this->createMock(AssetPropertyService::class);
        $service->expects(self::once())->method('setProperty')->with(42, '/Products/a.jpg', 'featured', 'bool', '1');

        (new SetPropertyAction($service))->apply($this->asset(), $this->createMock(AbstractObject::class), [
            'type' => 'set_property',
            'name' => 'featured',
            'property_type' => 'bool',
            'value' => true,
        ]);
    }

    #[Test]
    public function throwsWhenNameIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SetPropertyAction($this->createMock(AssetPropertyService::class)))
            ->apply($this->asset(), $this->createMock(AbstractObject::class), ['type' => 'set_property', 'value' => 'x']);
    }

    #[Test]
    public function derivesAValueFromAnObjectGetter(): void
    {
        $action = new class ($this->createMock(AssetPropertyService::class)) extends SetPropertyAction {
            public function exposedDerive(object $object, string $property): string
            {
                return $this->deriveFromObject($object, $property);
            }
        };

        $object = new class () {
            public function getProductCode(): string
            {
                return 'A-100';
            }

            public function isFeatured(): bool
            {
                return true;
            }
        };

        self::assertSame('A-100', $action->exposedDerive($object, 'productCode'));
        self::assertSame('1', $action->exposedDerive($object, 'featured'));
        self::assertSame('', $action->exposedDerive($object, 'missingField'));
    }
}
