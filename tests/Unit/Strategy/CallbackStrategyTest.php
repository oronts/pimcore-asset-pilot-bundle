<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Strategy;

use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Strategy\CallbackStrategy;
use Oronts\AssetPilotBundle\Strategy\ConflictStrategyInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

#[CoversClass(CallbackStrategy::class)]
class CallbackStrategyTest extends TestCase
{
    private function createRule(?string $callback): Rule
    {
        return new Rule(
            name: 'test', class: 'Product', fields: [], condition: null,
            targetPath: '/test', strategy: MoveStrategy::Callback, callback: $callback,
            priority: 10, enabled: true, filters: [],
        );
    }

    #[Test]
    public function returnsFalseWhenCallbackIsNull(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $strategy = new CallbackStrategy($container, new NullLogger());

        $asset = $this->createMock(Asset::class);
        $object = $this->createMock(AbstractObject::class);

        self::assertFalse($strategy->resolve($asset, $object, $this->createRule(null)));
    }

    #[Test]
    public function returnsFalseWhenServiceNotFound(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('app.my_callback')->willReturn(false);

        $strategy = new CallbackStrategy($container, new NullLogger());

        $asset = $this->createMock(Asset::class);
        $object = $this->createMock(AbstractObject::class);

        self::assertFalse($strategy->resolve($asset, $object, $this->createRule('app.my_callback')));
    }

    #[Test]
    public function returnsFalseWhenServiceNotCallable(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('app.my_callback')->willReturn(true);
        $container->method('get')->with('app.my_callback')->willReturn('not_callable_string');

        $strategy = new CallbackStrategy($container, new NullLogger());

        $asset = $this->createMock(Asset::class);
        $object = $this->createMock(AbstractObject::class);

        self::assertFalse($strategy->resolve($asset, $object, $this->createRule('app.my_callback')));
    }

    #[Test]
    public function returnsTrueWhenCallbackReturnsTrue(): void
    {
        $callable = fn () => true;

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('app.my_callback')->willReturn(true);
        $container->method('get')->with('app.my_callback')->willReturn($callable);

        $strategy = new CallbackStrategy($container, new NullLogger());

        $asset = $this->createMock(Asset::class);
        $object = $this->createMock(AbstractObject::class);

        self::assertTrue($strategy->resolve($asset, $object, $this->createRule('app.my_callback')));
    }

    #[Test]
    public function returnsFalseWhenCallbackReturnsFalse(): void
    {
        $callable = fn () => false;

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('app.my_callback')->willReturn(true);
        $container->method('get')->with('app.my_callback')->willReturn($callable);

        $strategy = new CallbackStrategy($container, new NullLogger());

        $asset = $this->createMock(Asset::class);
        $object = $this->createMock(AbstractObject::class);

        self::assertFalse($strategy->resolve($asset, $object, $this->createRule('app.my_callback')));
    }

    #[Test]
    public function castsTruthyValueToTrue(): void
    {
        $callable = fn () => 1;

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('app.my_callback')->willReturn(true);
        $container->method('get')->with('app.my_callback')->willReturn($callable);

        $strategy = new CallbackStrategy($container, new NullLogger());

        $asset = $this->createMock(Asset::class);
        $object = $this->createMock(AbstractObject::class);

        self::assertTrue($strategy->resolve($asset, $object, $this->createRule('app.my_callback')));
    }

    #[Test]
    public function passesCorrectArgumentsToCallback(): void
    {
        $receivedArgs = [];
        $callable = function () use (&$receivedArgs) {
            $receivedArgs = func_get_args();
            return true;
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('app.my_callback')->willReturn(true);
        $container->method('get')->with('app.my_callback')->willReturn($callable);

        $strategy = new CallbackStrategy($container, new NullLogger());

        $asset = $this->createMock(Asset::class);
        $object = $this->createMock(AbstractObject::class);
        $rule = $this->createRule('app.my_callback');

        $strategy->resolve($asset, $object, $rule);

        self::assertCount(3, $receivedArgs);
        self::assertSame($asset, $receivedArgs[0]);
        self::assertSame($object, $receivedArgs[1]);
        self::assertSame($rule, $receivedArgs[2]);
    }

    #[Test]
    public function delegatesToAConflictStrategyInterfaceService(): void
    {
        $custom = new class () implements ConflictStrategyInterface {
            public function resolve(Asset $asset, AbstractObject $object, Rule $rule): bool
            {
                return true;
            }

            public function supports(MoveStrategy $strategy): bool
            {
                return false;
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('app.custom_strategy')->willReturn(true);
        $container->method('get')->with('app.custom_strategy')->willReturn($custom);

        $strategy = new CallbackStrategy($container, new NullLogger());

        self::assertTrue($strategy->resolve(
            $this->createMock(Asset::class),
            $this->createMock(AbstractObject::class),
            $this->createRule('app.custom_strategy'),
        ));
    }

    #[Test]
    public function supportsCallbackStrategy(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $strategy = new CallbackStrategy($container, new NullLogger());

        self::assertTrue($strategy->supports(MoveStrategy::Callback));
    }

    #[Test]
    public function doesNotSupportAlwaysStrategy(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $strategy = new CallbackStrategy($container, new NullLogger());

        self::assertFalse($strategy->supports(MoveStrategy::Always));
    }
}
