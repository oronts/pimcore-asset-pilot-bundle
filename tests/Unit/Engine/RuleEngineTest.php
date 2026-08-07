<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Engine;

use Oronts\AssetPilotBundle\Condition\ConditionEvaluatorInterface;
use Oronts\AssetPilotBundle\Engine\RuleEngine;
use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Exception\PathResolutionException;
use Oronts\AssetPilotBundle\Filter\AssetFilterInterface;
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
    public function aLocaleScopedRuleOnlyMatchesItsDeclaredLocales(): void
    {
        $this->conditionEvaluator->method('evaluate')->willReturn(true);
        $this->filter->method('accept')->willReturn(true);
        $this->pathResolver->method('resolve')->willReturn('/x');

        $scoped = new Rule(
            name: 'de-only', class: 'Product', fields: [], condition: null,
            targetPath: '/t', strategy: MoveStrategy::Always, callback: null,
            priority: 10, enabled: true, filters: [], options: [], actions: [], locales: ['de'],
        );
        $engine = $this->createEngine([$scoped]);
        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $asset = $this->createMock(Asset::class);

        self::assertCount(1, $engine->matchField($object, $asset, 'image', 'de'), 'matches its declared locale');
        self::assertCount(0, $engine->matchField($object, $asset, 'image', 'en'), 'skips other locales');
        self::assertCount(0, $engine->matchField($object, $asset, 'image', null), 'a locale-scoped rule skips non-localized fields');
    }

    #[Test]
    public function matchFieldFailsClosedAndSkipsTheRuleWhenPathResolutionThrows(): void
    {
        $this->conditionEvaluator->method('evaluate')->willReturn(true);
        $this->filter->method('accept')->willReturn(true);
        $this->pathResolver->method('resolve')->willThrowException(new PathResolutionException('template blew up'));

        $engine = $this->createEngine([$this->createRule('r', 'Product', 10)]);
        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $asset = $this->createMock(Asset::class);

        self::assertCount(0, $engine->matchField($object, $asset, 'image', null), 'a template render failure must skip the rule, never produce a fallback match');
    }

    #[Test]
    public function explainReportsAPathResolutionFailureAsARejectionNotAMatch(): void
    {
        $this->conditionEvaluator->method('evaluate')->willReturn(true);
        $this->filter->method('accept')->willReturn(true);
        $this->pathResolver->method('resolve')->willThrowException(new PathResolutionException('template blew up'));

        $engine = $this->createEngine([$this->createRule('r', 'Product', 10)]);
        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $asset = $this->createMock(Asset::class);

        $result = $engine->explain($object, $asset, 'image', null);

        self::assertSame([], $result['matches'], 'a path failure yields no match');
        self::assertCount(1, $result['evaluations'], 'the failure is surfaced as an evaluation, not swallowed');
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
    public function equalPriorityRulesAreSortedByName(): void
    {
        $engine = $this->createEngine([
            $this->createRule('zeta', 'Product', 10),
            $this->createRule('alpha', 'Product', 10),
        ]);

        self::assertSame(['alpha', 'zeta'], array_map(static fn (Rule $rule): string => $rule->name, $engine->getRules()));
    }

    #[Test]
    public function ruleProvidersContributeRulesMergedAndSortedByPriority(): void
    {
        $configRule = $this->createRule('config', 'Product', 50);
        $providedRule = $this->createRule('provided', 'Product', 90);

        $provider = new class ($providedRule) implements \Oronts\AssetPilotBundle\Engine\RuleProviderInterface {
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
    public function duplicateNamesAcrossConfigurationAndProvidersAreRejected(): void
    {
        $rule = $this->createRule('same', 'Product', 50);
        $provider = new class ($rule) implements \Oronts\AssetPilotBundle\Engine\RuleProviderInterface {
            public function __construct(private readonly Rule $rule) {}

            public function getRules(): iterable
            {
                return [$this->rule];
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate rule name "same".');

        new RuleEngine(
            rules: [$rule],
            conditionEvaluator: $this->conditionEvaluator,
            pathResolver: $this->pathResolver,
            filter: $this->filter,
            logger: new NullLogger(),
            ruleProviders: [$provider],
        );
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
    public function explainReportsEveryGateAndTheResolvedMatch(): void
    {
        $rules = [
            Rule::fromConfig('disabled', ['class' => 'Product', 'target_path' => '/disabled', 'enabled' => false, 'priority' => 70]),
            Rule::fromConfig('class', ['class' => 'Category', 'target_path' => '/class', 'priority' => 60]),
            Rule::fromConfig('field', ['class' => 'Product', 'fields' => ['gallery'], 'target_path' => '/field', 'priority' => 50]),
            Rule::fromConfig('locale', ['class' => 'Product', 'fields' => ['image'], 'locales' => ['en'], 'target_path' => '/locale', 'priority' => 40]),
            Rule::fromConfig('condition', ['class' => 'Product', 'fields' => ['image'], 'condition' => 'false', 'target_path' => '/condition', 'priority' => 30]),
            Rule::fromConfig('filter', ['class' => 'Product', 'fields' => ['image'], 'condition' => 'true', 'target_path' => '/filter', 'priority' => 20]),
            Rule::fromConfig('matched', ['class' => 'Product', 'fields' => ['image'], 'condition' => 'true', 'target_path' => '/matched', 'priority' => 10]),
        ];
        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $asset = $this->createMock(Asset::class);
        $this->conditionEvaluator->method('evaluateStrict')->willReturnCallback(
            static fn (Concrete $object, Asset $asset, Rule $rule): bool => $rule->name !== 'condition',
        );
        $this->filter->method('accept')->willReturnCallback(
            static fn (Asset $asset, Concrete $object, Rule $rule): bool => $rule->name !== 'filter',
        );
        $this->pathResolver->expects(self::once())->method('resolve')->with($object, $asset, $rules[6], 'de')->willReturn('/resolved');

        $result = $this->createEngine($rules)->explain($object, $asset, 'image', 'de');
        $reasons = [];
        foreach ($result['evaluations'] as $evaluation) {
            $reasons[$evaluation->ruleName] = $evaluation->rejectionReason;
        }

        self::assertSame([
            'disabled' => 'disabled',
            'class' => 'class_mismatch',
            'field' => 'field_mismatch',
            'locale' => 'locale_mismatch',
            'condition' => 'condition_failed',
            'filter' => 'filter_rejected',
            'matched' => null,
        ], $reasons);
        self::assertCount(1, $result['matches']);
        self::assertSame('/resolved', $result['matches'][0]->resolvedPath);
    }

    #[Test]
    public function explainCapturesStrictConditionErrors(): void
    {
        $rule = Rule::fromConfig('broken', [
            'class' => 'Product',
            'condition' => 'broken()',
            'target_path' => '/broken',
        ]);
        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $asset = $this->createMock(Asset::class);
        $this->conditionEvaluator->method('evaluateStrict')->willThrowException(new \RuntimeException('unknown function'));

        $evaluation = $this->createEngine([$rule])->explain($object, $asset)['evaluations'][0];

        self::assertSame('condition_failed', $evaluation->rejectionReason);
        self::assertSame('unknown function', $evaluation->conditionError);
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
