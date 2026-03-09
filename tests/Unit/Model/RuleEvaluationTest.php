<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Model;

use Oronts\AssetPilotBundle\Model\RuleEvaluation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuleEvaluation::class)]
class RuleEvaluationTest extends TestCase
{
    #[Test]
    public function constructsMatchedEvaluation(): void
    {
        $eval = new RuleEvaluation(
            ruleName: 'product_images',
            matched: true,
            rejectionReason: null,
            conditionExpression: 'object.getSapId() != null',
            conditionResult: true,
            conditionError: null,
            filterDetails: null,
            resolvedPath: '/Products/SKU-123/Images',
            priority: 100,
            enabled: true,
        );

        self::assertSame('product_images', $eval->ruleName);
        self::assertTrue($eval->matched);
        self::assertNull($eval->rejectionReason);
        self::assertSame('object.getSapId() != null', $eval->conditionExpression);
        self::assertTrue($eval->conditionResult);
        self::assertNull($eval->conditionError);
        self::assertNull($eval->filterDetails);
        self::assertSame('/Products/SKU-123/Images', $eval->resolvedPath);
        self::assertSame(100, $eval->priority);
        self::assertTrue($eval->enabled);
    }

    #[Test]
    public function constructsSkippedEvaluation(): void
    {
        $eval = new RuleEvaluation(
            ruleName: 'category_rule',
            matched: false,
            rejectionReason: 'class_mismatch',
            conditionExpression: null,
            conditionResult: null,
            conditionError: null,
            filterDetails: 'expected Category, got Product',
            resolvedPath: null,
            priority: 50,
            enabled: true,
        );

        self::assertFalse($eval->matched);
        self::assertSame('class_mismatch', $eval->rejectionReason);
        self::assertSame('expected Category, got Product', $eval->filterDetails);
        self::assertNull($eval->resolvedPath);
    }

    #[Test]
    public function constructsDisabledEvaluation(): void
    {
        $eval = new RuleEvaluation(
            ruleName: 'old_rule',
            matched: false,
            rejectionReason: 'disabled',
            conditionExpression: null,
            conditionResult: null,
            conditionError: null,
            filterDetails: null,
            resolvedPath: null,
            priority: 10,
            enabled: false,
        );

        self::assertFalse($eval->enabled);
        self::assertSame('disabled', $eval->rejectionReason);
    }

    #[Test]
    public function constructsConditionFailedEvaluation(): void
    {
        $eval = new RuleEvaluation(
            ruleName: 'conditional_rule',
            matched: false,
            rejectionReason: 'condition_failed',
            conditionExpression: 'object.isActive()',
            conditionResult: false,
            conditionError: 'Method "isActive" not found',
            filterDetails: null,
            resolvedPath: null,
            priority: 75,
            enabled: true,
        );

        self::assertSame('condition_failed', $eval->rejectionReason);
        self::assertFalse($eval->conditionResult);
        self::assertSame('Method "isActive" not found', $eval->conditionError);
    }
}
