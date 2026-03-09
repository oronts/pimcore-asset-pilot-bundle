<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Engine;

use Oronts\AssetPilotBundle\Condition\ConditionEvaluatorInterface;
use Oronts\AssetPilotBundle\Engine\RuleEngine;
use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Filter\AssetFilterInterface;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Model\RuleEvaluation;
use Oronts\AssetPilotBundle\PathResolver\PathResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\Concrete;
use Psr\Log\NullLogger;

#[CoversClass(RuleEngine::class)]
class RuleEngineExplainTest extends TestCase
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

    private function createRule(string $name, string $class, int $priority, bool $enabled = true, array $fields = [], ?string $condition = null): Rule
    {
        return new Rule(
            name: $name, class: $class, fields: $fields, condition: $condition,
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

    private function createObject(string $className = 'Product'): Concrete
    {
        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn($className);
        $object->method('getId')->willReturn(1);

        return $object;
    }

    private function createAsset(): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(1);
        $asset->method('getRealFullPath')->willReturn('/uploads/photo.jpg');

        return $asset;
    }

    #[Test]
    public function explainReturnsEvaluationsForAllRules(): void
    {
        $r1 = $this->createRule('rule1', 'Product', 100);
        $r2 = $this->createRule('rule2', 'Category', 50);
        $r3 = $this->createRule('rule3', 'Product', 10, enabled: false);

        $this->conditionEvaluator->method('evaluate')->willReturn(true);
        $this->filter->method('accept')->willReturn(true);
        $this->pathResolver->method('resolve')->willReturn('/resolved');

        $engine = $this->createEngine([$r1, $r2, $r3]);
        $result = $engine->explain($this->createObject(), $this->createAsset());

        self::assertCount(3, $result['evaluations']);
        self::assertCount(1, $result['matches']);
    }

    #[Test]
    public function explainDisabledRuleShowsDisabledReason(): void
    {
        $rule = $this->createRule('disabled_rule', 'Product', 10, enabled: false);
        $engine = $this->createEngine([$rule]);

        $result = $engine->explain($this->createObject(), $this->createAsset());

        self::assertCount(1, $result['evaluations']);
        $eval = $result['evaluations'][0];
        self::assertFalse($eval->matched);
        self::assertSame('disabled', $eval->rejectionReason);
        self::assertFalse($eval->enabled);
    }

    #[Test]
    public function explainClassMismatchShowsExpectedVsActual(): void
    {
        $rule = $this->createRule('category_rule', 'Category', 10);
        $engine = $this->createEngine([$rule]);

        $result = $engine->explain($this->createObject('Product'), $this->createAsset());

        $eval = $result['evaluations'][0];
        self::assertFalse($eval->matched);
        self::assertSame('class_mismatch', $eval->rejectionReason);
        self::assertStringContainsString('expected Category', $eval->filterDetails);
        self::assertStringContainsString('got Product', $eval->filterDetails);
    }

    #[Test]
    public function explainFieldMismatchRecorded(): void
    {
        $rule = $this->createRule('field_rule', 'Product', 10, fields: ['productImages']);
        $engine = $this->createEngine([$rule]);

        $result = $engine->explain($this->createObject(), $this->createAsset(), 'qrCode');

        $eval = $result['evaluations'][0];
        self::assertFalse($eval->matched);
        self::assertSame('field_mismatch', $eval->rejectionReason);
        self::assertStringContainsString('qrCode', $eval->filterDetails);
    }

    #[Test]
    public function explainConditionFailureShowsExpressionAndError(): void
    {
        $rule = $this->createRule('cond_rule', 'Product', 10, condition: 'object.isActive()');

        $this->conditionEvaluator->method('evaluate')->willReturn(false);

        $engine = $this->createEngine([$rule]);
        $result = $engine->explain($this->createObject(), $this->createAsset());

        $eval = $result['evaluations'][0];
        self::assertFalse($eval->matched);
        self::assertSame('condition_failed', $eval->rejectionReason);
        self::assertSame('object.isActive()', $eval->conditionExpression);
        self::assertFalse($eval->conditionResult);
    }

    #[Test]
    public function explainFilterRejectionRecorded(): void
    {
        $rule = $this->createRule('filter_rule', 'Product', 10);

        $this->conditionEvaluator->method('evaluate')->willReturn(true);
        $this->filter->method('accept')->willReturn(false);

        $engine = $this->createEngine([$rule]);
        $result = $engine->explain($this->createObject(), $this->createAsset());

        $eval = $result['evaluations'][0];
        self::assertFalse($eval->matched);
        self::assertSame('filter_rejected', $eval->rejectionReason);
    }

    #[Test]
    public function explainMatchedRuleHasResolvedPath(): void
    {
        $rule = $this->createRule('match_rule', 'Product', 10);

        $this->conditionEvaluator->method('evaluate')->willReturn(true);
        $this->filter->method('accept')->willReturn(true);
        $this->pathResolver->method('resolve')->willReturn('/Products/SKU-123/Images');

        $engine = $this->createEngine([$rule]);
        $result = $engine->explain($this->createObject(), $this->createAsset());

        $eval = $result['evaluations'][0];
        self::assertTrue($eval->matched);
        self::assertNull($eval->rejectionReason);
        self::assertSame('/Products/SKU-123/Images', $eval->resolvedPath);
        self::assertTrue($eval->conditionResult);
    }

    #[Test]
    public function explainNoConditionDefaultsToTrue(): void
    {
        $rule = $this->createRule('no_cond', 'Product', 10);

        $this->conditionEvaluator->method('evaluate')->willReturn(true);
        $this->filter->method('accept')->willReturn(true);
        $this->pathResolver->method('resolve')->willReturn('/path');

        $engine = $this->createEngine([$rule]);
        $result = $engine->explain($this->createObject(), $this->createAsset());

        $eval = $result['evaluations'][0];
        self::assertTrue($eval->matched);
        self::assertTrue($eval->conditionResult);
    }

    #[Test]
    public function explainWithLocalePassesToPathResolver(): void
    {
        $rule = $this->createRule('localized', 'Product', 10);

        $this->conditionEvaluator->method('evaluate')->willReturn(true);
        $this->filter->method('accept')->willReturn(true);
        $this->pathResolver->method('resolve')->willReturn('/de/products');

        $engine = $this->createEngine([$rule]);
        $result = $engine->explain($this->createObject(), $this->createAsset(), 'images', 'de_DE');

        self::assertCount(1, $result['matches']);
        self::assertSame('de_DE', $result['matches'][0]->locale);
    }

    #[Test]
    public function explainConditionExceptionCaptured(): void
    {
        $rule = $this->createRule('error_rule', 'Product', 10, condition: 'broken.expression()');

        $this->conditionEvaluator->method('evaluate')->willThrowException(new \RuntimeException('Syntax error'));

        $engine = $this->createEngine([$rule]);
        $result = $engine->explain($this->createObject(), $this->createAsset());

        $eval = $result['evaluations'][0];
        self::assertFalse($eval->matched);
        self::assertSame('condition_failed', $eval->rejectionReason);
        self::assertSame('Syntax error', $eval->conditionError);
        self::assertFalse($eval->conditionResult);
    }
}
