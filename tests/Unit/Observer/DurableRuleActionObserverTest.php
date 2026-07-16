<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Observer;

use Oronts\AssetPilotBundle\Action\RuleActionInterface;
use Oronts\AssetPilotBundle\Action\RuleActionResolver;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;
use Oronts\AssetPilotBundle\Enum\OperationKind;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\DeliveryEnvelope;
use Oronts\AssetPilotBundle\Model\OperationIntent;
use Oronts\AssetPilotBundle\Observer\DurableRuleActionObserver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

#[CoversClass(DurableRuleActionObserver::class)]
final class DurableRuleActionObserverTest extends TestCase
{
    #[Test]
    public function preparesImmutableActionDataAndAppliesOnlyThatData(): void
    {
        $asset = $this->createMock(Asset::class);
        $object = $this->createMock(AbstractObject::class);
        $action = $this->createMock(RuleActionInterface::class);
        $action->method('getType')->willReturn('set_property');
        $action->expects(self::once())->method('prepare')->with($asset, $object, [
            'type' => 'set_property',
            'name' => 'sku',
            'from' => 'sku',
        ])->willReturn(['name' => 'sku', 'type' => 'text', 'value' => 'A-100']);
        $action->expects(self::once())->method('applyPrepared')->with($asset, [
            'name' => 'sku',
            'type' => 'text',
            'value' => 'A-100',
        ]);
        $observer = $this->observer(new RuleActionResolver([$action]), $asset, $object);
        self::assertSame('publish', $observer->requiredAssetPermission());
        $intent = $this->intent([['type' => 'set_property', 'name' => 'sku', 'from' => 'sku']]);

        $prepared = [...$observer->prepare($intent)];
        self::assertCount(1, $prepared);
        self::assertSame('rule-action:0000:set_property', $prepared[0]->deliveryKey);
        self::assertSame(OperationDeliveryOutcome::Success, $prepared[0]->outcome);

        $observer->deliver(new DeliveryEnvelope(
            str_repeat('a', 64),
            5,
            $prepared[0]->deliveryKey,
            $observer->id(),
            $prepared[0]->outcome,
            $intent,
            $prepared[0]->payload,
            1,
            'worker',
        ));
    }

    #[Test]
    public function unregisteredConfiguredActionFailsBeforeTheAssetMove(): void
    {
        $observer = $this->observer(
            new RuleActionResolver([]),
            $this->createMock(Asset::class),
            $this->createMock(AbstractObject::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $observer->prepare($this->intent([['type' => 'missing']]));
    }

    private function observer(RuleActionResolver $resolver, Asset $asset, AbstractObject $object): DurableRuleActionObserver
    {
        return new class ($resolver, $asset, $object) extends DurableRuleActionObserver {
            public function __construct(
                RuleActionResolver $resolver,
                private readonly Asset $asset,
                private readonly AbstractObject $object,
            ) {
                parent::__construct($resolver);
            }

            protected function loadAsset(int $assetId): ?Asset
            {
                return $this->asset;
            }

            protected function loadObject(int $objectId): ?AbstractObject
            {
                return $this->object;
            }
        };
    }

    /** @param list<array<string, mixed>> $actions */
    private function intent(array $actions): OperationIntent
    {
        return new OperationIntent(
            OperationKind::Move,
            7,
            '/source/a.jpg',
            '/target/a.jpg',
            9,
            'Product',
            'images',
            TriggerType::Manual,
            ActorContext::system(),
            ['actions' => $actions],
        );
    }
}
