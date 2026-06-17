<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Engine;

use Oronts\AssetPilotBundle\Condition\ConditionEvaluatorInterface;
use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Filter\AssetFilterInterface;
use Oronts\AssetPilotBundle\Engine\RuleEngine;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\PathResolver\PathResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\Concrete;
use Psr\Log\NullLogger;

#[CoversClass(RuleEngine::class)]
class RuleEngineTest extends TestCase
{
    private ConditionEvaluatorInterface $conditionEvaluator;
    private PathResolverInterface $pathResolver;
    private AssetFilterInterface $filter;

    protected function setUp(): void
    {
        $this->conditionEvaluator = $this->createMock(ConditionEvaluatorInterface::class);
        $this->pathResolver = $this->createMock(PathResolverInterface::class);
        $this->filter = $this->createMock(AssetFilterInterface::class);
    }

    private function createRule(string $name, string $class, int $priority, bool $enabled = true, array $fields = []): Rule
    {
        return new Rule(
            name: $name, class: $class, fields: $fields, condition: null,
            targetPath: '/target/' . $name, strategy: MoveStrategy::Always, callback: null,
            priority: $priority, enabled: $enabled, filters: [],
        );
    }

    private function createEngine(array $rules): RuleEngine
    {
        return new RuleEngine(
            rules: $rules,
            conditionEvaluator: $this->conditionEvaluator,
            pathResolver: $this->pathResolver,
            filter: $this->filter,
            logger: new NullLogger(),
        );
    }

    #[Test]
    public function sortedRulesByPriorityDescending(): void
    {
        $r1 = $this->createRule('low', 'Product', 1);
        $r2 = $this->createRule('high', 'Product', 100);
        $r3 = $this->createRule('mid', 'Product', 50);

        $engine = $this->createEngine([$r1, $r2, $r3]);
        $rules = $engine->getRules();

        self::assertSame('high', $rules[0]->name);
        self::assertSame('mid', $rules[1]->name);
        self::assertSame('low', $rules[2]->name);
    }

    #[Test]
    public function ruleProvidersContributeRulesMergedAndSortedByPriority(): void
    {
        $configRule = $this->createRule('config', 'Product', 50);
        $providedRule = $this->createRule('provided', 'Product', 90);

        $provider = new class($providedRule) implements \Oronts\AssetPilotBundle\Engine\RuleProviderInterface {
            public function __construct(private readonly Rule $rule) {}

            public function getRules(): iterable
            {
                return [$this->rule];
            }
        };

        $engine = new RuleEngine(
            rules: [$configRule],
            conditionEvaluator: $this->conditionEvaluator,
            pathResolver: $this->pathResolver,
            filter: $this->filter,
            logger: new NullLogger(),
            ruleProviders: [$provider],
        );

        $rules = $engine->getRules();
        self::assertCount(2, $rules);
        self::assertSame('provided', $rules[0]->name);
        self::assertSame('config', $rules[1]->name);
    }

    #[Test]
    public function matchReturnsEmptyWhenNoRules(): void
    {
        $engine = $this->createEngine([]);
        $object = $this->createMock(Concrete::class);
        $asset = $this->createMock(Asset::class);

        self::assertSame([], $engine->match($object, $asset));
    }

    #[Test]
    public function matchSkipsDisabledRules(): void
    {
        $rule = $this->createRule('disabled', 'Product', 10, enabled: false);
        $engine = $this->createEngine([$rule]);

        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $asset = $this->createMock(Asset::class);

        self::assertSame([], $engine->match($object, $asset));
    }

    #[Test]
    public function matchSkipsClassMismatch(): void
    {
        $rule = $this->createRule('product-only', 'Product', 10);

        $this->conditionEvaluator->method('evaluate')->willReturn(true);
        $this->filter->method('accept')->willReturn(true);

        $engine = $this->createEngine([$rule]);

        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Category');
        $asset = $this->createMock(Asset::class);

        self::assertSame([], $engine->match($object, $asset));
    }

    #[Test]
    public function matchAcceptsWildcardClass(): void
    {
        $rule = $this->createRule('wildcard', '*', 10);

        $this->conditionEvaluator->method('evaluate')->willReturn(true);
        $this->filter->method('accept')->willReturn(true);
        $this->pathResolver->method('resolve')->willReturn('/resolved');

        $engine = $this->createEngine([$rule]);

        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('AnyClass');
        $object->method('getId')->willReturn(1);
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(1);

        $matches = $engine->match($object, $asset);
        self::assertCount(1, $matches);
    }

    #[Test]
    public function matchSkipsWhenConditionFails(): void
    {
        $rule = $this->createRule('cond-fail', 'Product', 10);

        $this->conditionEvaluator->method('evaluate')->willReturn(false);

        $engine = $this->createEngine([$rule]);

        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getId')->willReturn(1);
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(1);

        self::assertSame([], $engine->match($object, $asset));
    }

    #[Test]
    public function matchSkipsWhenFilterRejects(): void
    {
        $rule = $this->createRule('filter-fail', 'Product', 10);

        $this->conditionEvaluator->method('evaluate')->willReturn(true);
        $this->filter->method('accept')->willReturn(false);

        $engine = $this->createEngine([$rule]);

        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getId')->willReturn(1);
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(1);

        self::assertSame([], $engine->match($object, $asset));
    }

    #[Test]
    public function matchReturnsMatchWithResolvedPath(): void
    {
        $rule = $this->createRule('ok', 'Product', 10);

        $this->conditionEvaluator->method('evaluate')->willReturn(true);
        $this->filter->method('accept')->willReturn(true);
        $this->pathResolver->method('resolve')->willReturn('/products/images');

        $engine = $this->createEngine([$rule]);

        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getId')->willReturn(5);
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(10);

        $matches = $engine->match($object, $asset);
        self::assertCount(1, $matches);
        self::assertSame('/products/images', $matches[0]->resolvedPath);
        self::assertSame($rule, $matches[0]->rule);
        self::assertSame($object, $matches[0]->object);
        self::assertSame($asset, $matches[0]->asset);
        self::assertNull($matches[0]->locale);
    }

    #[Test]
    public function matchReturnsMultipleMatches(): void
    {
        $r1 = $this->createRule('rule1', 'Product', 20);
        $r2 = $this->createRule('rule2', 'Product', 10);

        $this->conditionEvaluator->method('evaluate')->willReturn(true);
        $this->filter->method('accept')->willReturn(true);
        $this->pathResolver->method('resolve')->willReturn('/path');

        $engine = $this->createEngine([$r1, $r2]);

        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getId')->willReturn(1);
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(1);

        $matches = $engine->match($object, $asset);
        self::assertCount(2, $matches);
        self::assertSame('rule1', $matches[0]->rule->name);
        self::assertSame('rule2', $matches[1]->rule->name);
    }

    #[Test]
    public function matchFieldSkipsNonMatchingFields(): void
    {
        $rule = $this->createRule('field-rule', 'Product', 10, fields: ['productImages']);

        $this->conditionEvaluator->method('evaluate')->willReturn(true);
        $this->filter->method('accept')->willReturn(true);

        $engine = $this->createEngine([$rule]);

        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $asset = $this->createMock(Asset::class);

        $matches = $engine->matchField($object, $asset, 'qrCode');
        self::assertSame([], $matches);
    }

    #[Test]
    public function matchFieldAcceptsMatchingField(): void
    {
        $rule = $this->createRule('field-rule', 'Product', 10, fields: ['productImages', 'qrCode']);

        $this->conditionEvaluator->method('evaluate')->willReturn(true);
        $this->filter->method('accept')->willReturn(true);
        $this->pathResolver->method('resolve')->willReturn('/resolved');

        $engine = $this->createEngine([$rule]);

        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getId')->willReturn(1);
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(1);

        $matches = $engine->matchField($object, $asset, 'qrCode');
        self::assertCount(1, $matches);
    }

    #[Test]
    public function matchFieldAcceptsEmptyFieldsAsWildcard(): void
    {
        $rule = $this->createRule('all-fields', 'Product', 10, fields: []);

        $this->conditionEvaluator->method('evaluate')->willReturn(true);
        $this->filter->method('accept')->willReturn(true);
        $this->pathResolver->method('resolve')->willReturn('/resolved');

        $engine = $this->createEngine([$rule]);

        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getId')->willReturn(1);
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(1);

        $matches = $engine->matchField($object, $asset, 'anyField');
        self::assertCount(1, $matches);
    }

    #[Test]
    public function matchFieldIncludesLocale(): void
    {
        $rule = $this->createRule('localized', 'Product', 10);

        $this->conditionEvaluator->method('evaluate')->willReturn(true);
        $this->filter->method('accept')->willReturn(true);
        $this->pathResolver->method('resolve')->willReturn('/de/products');

        $engine = $this->createEngine([$rule]);

        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getId')->willReturn(1);
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(1);

        $matches = $engine->matchField($object, $asset, 'images', 'de_DE');
        self::assertCount(1, $matches);
        self::assertSame('de_DE', $matches[0]->locale);
    }

    #[Test]
    public function findRulesForClassReturnsMatchingEnabledRules(): void
    {
        $r1 = $this->createRule('product-rule', 'Product', 10);
        $r2 = $this->createRule('category-rule', 'Category', 20);
        $r3 = $this->createRule('disabled', 'Product', 5, enabled: false);
        $r4 = $this->createRule('wildcard', '*', 1);

        $engine = $this->createEngine([$r1, $r2, $r3, $r4]);

        $result = $engine->findRulesForClass('Product');
        self::assertCount(2, $result);
        self::assertSame('product-rule', $result[0]->name);
        self::assertSame('wildcard', $result[1]->name);
    }

    #[Test]
    public function parsesArrayConfigToRules(): void
    {
        $config = [
            'name' => 'from-config',
            'class' => 'Product',
            'fields' => [],
            'target_path' => '/from/config',
            'strategy' => 'always',
            'priority' => 50,
            'enabled' => true,
        ];

        $engine = $this->createEngine([$config]);
        $rules = $engine->getRules();

        self::assertCount(1, $rules);
        self::assertSame('from-config', $rules[0]->name);
        self::assertSame('Product', $rules[0]->class);
    }
}
