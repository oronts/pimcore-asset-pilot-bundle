<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Model;

use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Model\Rule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Rule::class)]
class RuleTest extends TestCase
{
    #[Test]
    public function constructsWithAllProperties(): void
    {
        $rule = new Rule(
            name: 'test_rule',
            class: 'Product',
            fields: ['productImages'],
            condition: 'object.getSapId() != null',
            targetPath: '/Products/{{ sapId }}/Images',
            strategy: MoveStrategy::Always,
            callback: null,
            priority: 100,
            enabled: true,
            filters: ['types' => ['image']],
        );

        self::assertSame('test_rule', $rule->name);
        self::assertSame('Product', $rule->class);
        self::assertSame(['productImages'], $rule->fields);
        self::assertSame('object.getSapId() != null', $rule->condition);
        self::assertSame('/Products/{{ sapId }}/Images', $rule->targetPath);
        self::assertSame(MoveStrategy::Always, $rule->strategy);
        self::assertNull($rule->callback);
        self::assertSame(100, $rule->priority);
        self::assertTrue($rule->enabled);
        self::assertSame(['types' => ['image']], $rule->filters);
    }

    #[Test]
    public function fromConfigCreatesRuleWithDefaults(): void
    {
        $rule = Rule::fromConfig('my_rule', [
            'class' => 'Product',
            'target_path' => '/Assets/{{ sapId }}',
        ]);

        self::assertSame('my_rule', $rule->name);
        self::assertSame('Product', $rule->class);
        self::assertSame([], $rule->fields);
        self::assertNull($rule->condition);
        self::assertSame('/Assets/{{ sapId }}', $rule->targetPath);
        self::assertSame(MoveStrategy::Always, $rule->strategy);
        self::assertNull($rule->callback);
        self::assertSame(10, $rule->priority);
        self::assertTrue($rule->enabled);
        self::assertSame([], $rule->filters);
        self::assertSame([], $rule->options);
    }

    #[Test]
    public function fromConfigCreatesRuleWithAllOptions(): void
    {
        $rule = Rule::fromConfig('full_rule', [
            'class' => 'Category',
            'fields' => ['images', 'documents'],
            'condition' => 'object.getId() > 0',
            'target_path' => '/Categories/{{ className }}',
            'strategy' => 'first_assignment',
            'callback' => 'app.my_callback',
            'priority' => 50,
            'enabled' => false,
            'filters' => ['types' => ['image'], 'max_size' => 10485760],
            'options' => ['threshold' => 5, 'mode' => 'strict'],
        ]);

        self::assertSame('full_rule', $rule->name);
        self::assertSame('Category', $rule->class);
        self::assertSame(['images', 'documents'], $rule->fields);
        self::assertSame('object.getId() > 0', $rule->condition);
        self::assertSame(MoveStrategy::FirstAssignment, $rule->strategy);
        self::assertSame('app.my_callback', $rule->callback);
        self::assertSame(50, $rule->priority);
        self::assertFalse($rule->enabled);
        self::assertSame(['types' => ['image'], 'max_size' => 10485760], $rule->filters);
        self::assertSame(['threshold' => 5, 'mode' => 'strict'], $rule->options);
    }

    #[Test]
    public function fromConfigWithCallbackStrategy(): void
    {
        $rule = Rule::fromConfig('cb_rule', [
            'class' => 'Product',
            'target_path' => '/Test',
            'strategy' => 'callback',
            'callback' => 'my.service',
        ]);

        self::assertSame(MoveStrategy::Callback, $rule->strategy);
        self::assertSame('my.service', $rule->callback);
    }
}
