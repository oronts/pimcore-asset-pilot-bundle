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
            conditionExpression: 'object.getProductCode() != null',
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
        self::assertSame('object.getProductCode() != null', $eval->conditionExpression);
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

    #[Test]
    public function describeMatchedShowsResolvedPath(): void
    {
        $eval = $this->evaluation(matched: true, resolvedPath: '/Products/SKU-1/Images');

        self::assertSame('-> /Products/SKU-1/Images', $eval->describe());
    }

    #[Test]
    public function describeMatchedWithoutPathFallsBack(): void
    {
        $eval = $this->evaluation(matched: true, resolvedPath: null);

        self::assertSame('-> (unknown path)', $eval->describe());
    }

    #[Test]
    public function describeClassMismatchIncludesFilterDetails(): void
    {
        $eval = $this->evaluation(rejectionReason: 'class_mismatch', filterDetails: 'got Product');

        self::assertSame('class_mismatch: got Product', $eval->describe());
    }

    #[Test]
    public function describeLocaleMismatchIncludesFilterDetails(): void
    {
        $eval = $this->evaluation(rejectionReason: 'locale_mismatch', filterDetails: 'locale "de" not in [en]');

        self::assertSame('locale_mismatch: locale "de" not in [en]', $eval->describe());
    }

    #[Test]
    public function describeConditionFailedIncludesExpressionAndError(): void
    {
        $eval = $this->evaluation(
            rejectionReason: 'condition_failed',
            conditionExpression: 'object.isActive()',
            conditionError: 'boom',
        );

        self::assertSame('condition_failed: object.isActive() (error: boom)', $eval->describe());
    }

    #[Test]
    public function describeUnknownReasonFallsBackToTheReason(): void
    {
        $eval = $this->evaluation(rejectionReason: 'something_new');

        self::assertSame('something_new', $eval->describe());
    }

    private function evaluation(
        bool $matched = false,
        ?string $rejectionReason = null,
        ?string $conditionExpression = null,
        ?string $conditionError = null,
        ?string $filterDetails = null,
        ?string $resolvedPath = null,
    ): RuleEvaluation {
        return new RuleEvaluation(
            ruleName: 'r',
            matched: $matched,
            rejectionReason: $rejectionReason,
            conditionExpression: $conditionExpression,
            conditionResult: null,
            conditionError: $conditionError,
            filterDetails: $filterDetails,
            resolvedPath: $resolvedPath,
            priority: 10,
            enabled: true,
        );
    }
}
