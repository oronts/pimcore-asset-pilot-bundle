<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Action;

use Oronts\AssetPilotBundle\Action\RuleActionDeliveryContextInterface;
use Oronts\AssetPilotBundle\Action\SetPropertyAction;
use Oronts\AssetPilotBundle\Service\AssetPropertyService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\Property;

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
    public function preparesAStaticValueBeforeTheMove(): void
    {
        $payload = (new SetPropertyAction($this->createMock(AssetPropertyService::class)))->prepare($this->asset(), $this->createMock(AbstractObject::class), [
            'type' => 'set_property',
            'name' => 'cdn_ready',
            'value' => 'yes',
        ]);

        self::assertSame(['name' => 'cdn_ready', 'type' => 'text', 'value' => 'yes'], $payload);
    }

    #[Test]
    public function castsABooleanValueToDbBool(): void
    {
        $payload = (new SetPropertyAction($this->createMock(AssetPropertyService::class)))->prepare($this->asset(), $this->createMock(AbstractObject::class), [
            'type' => 'set_property',
            'name' => 'featured',
            'property_type' => 'bool',
            'value' => true,
        ]);

        self::assertSame(['name' => 'featured', 'type' => 'bool', 'value' => '1'], $payload);
    }

    #[Test]
    public function appliesPreparedDataThroughTheLockedPropertyService(): void
    {
        $service = $this->createMock(AssetPropertyService::class);
        $asset = $this->asset();
        $asset->method('getProperty')->with('cdn_ready', true)->willReturn(null);
        $service->expects(self::once())->method('setPropertyOnLockedAsset')->with($asset, 'cdn_ready', 'text', 'yes');

        (new SetPropertyAction($service))->applyPrepared($asset, ['name' => 'cdn_ready', 'type' => 'text', 'value' => 'yes'], $this->createMock(RuleActionDeliveryContextInterface::class));
    }

    #[Test]
    public function repeatedPreparedDeliveryDoesNotCreateAnotherAssetVersion(): void
    {
        $property = (new Property())->setName('cdn_ready')->setType('text')->setData('yes');
        $asset = $this->asset();
        $asset->method('getProperty')->with('cdn_ready', true)->willReturn($property);
        $service = $this->createMock(AssetPropertyService::class);
        $service->expects(self::never())->method('setPropertyOnLockedAsset');

        (new SetPropertyAction($service))->applyPrepared($asset, ['name' => 'cdn_ready', 'type' => 'text', 'value' => 'yes'], $this->createMock(RuleActionDeliveryContextInterface::class));
    }

    #[Test]
    public function throwsWhenNameIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SetPropertyAction($this->createMock(AssetPropertyService::class)))
            ->prepare($this->asset(), $this->createMock(AbstractObject::class), ['type' => 'set_property', 'value' => 'x']);
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
