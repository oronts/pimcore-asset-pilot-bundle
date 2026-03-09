<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Event;

use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Event\AssetMoveEvent;
use Oronts\AssetPilotBundle\Model\Rule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

#[CoversClass(AssetMoveEvent::class)]
class AssetMoveEventTest extends TestCase
{
    private function createEvent(): AssetMoveEvent
    {
        return new AssetMoveEvent(
            asset: $this->createMock(Asset::class),
            sourcePath: '/source/image.png',
            targetPath: '/target/image.png',
            object: $this->createMock(AbstractObject::class),
            rule: new Rule(
                name: 'test', class: 'Product', fields: [], condition: null,
                targetPath: '/test', strategy: MoveStrategy::Always, callback: null,
                priority: 10, enabled: true, filters: [],
            ),
            triggerType: TriggerType::ObjectSave,
        );
    }

    #[Test]
    public function isNotCancelledByDefault(): void
    {
        $event = $this->createEvent();
        self::assertFalse($event->isCancelled());
    }

    #[Test]
    public function cancelSetsCancelledFlag(): void
    {
        $event = $this->createEvent();
        $event->cancel();
        self::assertTrue($event->isCancelled());
    }

    #[Test]
    public function cancelStopsPropagation(): void
    {
        $event = $this->createEvent();
        self::assertFalse($event->isPropagationStopped());
        $event->cancel();
        self::assertTrue($event->isPropagationStopped());
    }

    #[Test]
    public function exposesReadonlyProperties(): void
    {
        $asset = $this->createMock(Asset::class);
        $object = $this->createMock(AbstractObject::class);
        $rule = new Rule(
            name: 'my-rule', class: 'Product', fields: [], condition: null,
            targetPath: '/test', strategy: MoveStrategy::Always, callback: null,
            priority: 10, enabled: true, filters: [],
        );

        $event = new AssetMoveEvent(
            asset: $asset,
            sourcePath: '/from/file.jpg',
            targetPath: '/to/file.jpg',
            object: $object,
            rule: $rule,
            triggerType: TriggerType::Manual,
        );

        self::assertSame($asset, $event->asset);
        self::assertSame('/from/file.jpg', $event->sourcePath);
        self::assertSame('/to/file.jpg', $event->targetPath);
        self::assertSame($object, $event->object);
        self::assertSame($rule, $event->rule);
        self::assertSame(TriggerType::Manual, $event->triggerType);
    }
}
