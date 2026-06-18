<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\EventListener;

use Oronts\AssetPilotBundle\Action\RuleActionInterface;
use Oronts\AssetPilotBundle\Action\RuleActionResolver;
use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Event\AssetMoveEvent;
use Oronts\AssetPilotBundle\EventListener\RuleActionListener;
use Oronts\AssetPilotBundle\Model\Rule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\NullLogger;

#[CoversClass(RuleActionListener::class)]
class RuleActionListenerTest extends TestCase
{
    /** @param array<int, array<string, mixed>> $actions */
    private function event(array $actions, bool $dryRun = false): AssetMoveEvent
    {
        $rule = new Rule('r', 'Product', [], null, '/P', MoveStrategy::Always, null, 10, true, [], [], $actions);

        return new AssetMoveEvent(
            $this->createMock(Asset::class),
            '/old/a.jpg',
            '/new/a.jpg',
            $this->createMock(AbstractObject::class),
            $rule,
            TriggerType::ObjectSave,
            dryRun: $dryRun,
        );
    }

    private function listener(RuleActionResolver $resolver): RuleActionListener
    {
        return new RuleActionListener($resolver, new NullLogger());
    }

    #[Test]
    public function appliesEachConfiguredActionAfterAMove(): void
    {
        $action = $this->createMock(RuleActionInterface::class);
        $action->method('getType')->willReturn('set_property');
        $action->expects(self::once())->method('apply');

        $this->listener(new RuleActionResolver([$action]))
            ->onPostMove($this->event([['type' => 'set_property', 'name' => 'x', 'value' => '1']]));
    }

    #[Test]
    public function doesNothingInDryRun(): void
    {
        $action = $this->createMock(RuleActionInterface::class);
        $action->method('getType')->willReturn('set_property');
        $action->expects(self::never())->method('apply');

        $this->listener(new RuleActionResolver([$action]))
            ->onPostMove($this->event([['type' => 'set_property']], dryRun: true));
    }

    #[Test]
    public function skipsActionsWithoutAResolvedTypeWithoutFailing(): void
    {
        $this->expectNotToPerformAssertions();

        $this->listener(new RuleActionResolver([]))
            ->onPostMove($this->event([['type' => 'unregistered'], ['no_type' => true]]));
    }

    #[Test]
    public function aFailingActionIsSwallowedSoTheMoveStands(): void
    {
        $this->expectNotToPerformAssertions();

        $action = $this->createMock(RuleActionInterface::class);
        $action->method('getType')->willReturn('set_property');
        $action->method('apply')->willThrowException(new \RuntimeException('boom'));

        $this->listener(new RuleActionResolver([$action]))
            ->onPostMove($this->event([['type' => 'set_property']]));
    }
}
