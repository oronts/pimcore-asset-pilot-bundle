<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Condition;

use Oronts\AssetPilotBundle\Condition\ExpressionConditionEvaluator;
use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Model\Rule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Psr\Log\NullLogger;

#[CoversClass(ExpressionConditionEvaluator::class)]
class ExpressionConditionEvaluatorTest extends TestCase
{
    private ExpressionConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new ExpressionConditionEvaluator(new NullLogger());
    }

    private function createRule(?string $condition): Rule
    {
        return new Rule(
            name: 'test', class: 'Product', fields: [], condition: $condition,
            targetPath: '/test', strategy: MoveStrategy::Always, callback: null,
            priority: 10, enabled: true, filters: [],
        );
    }

    #[Test]
    public function returnsTrueWhenConditionIsNull(): void
    {
        $object = $this->createMock(AbstractObject::class);
        $asset = $this->createMock(Asset::class);

        self::assertTrue($this->evaluator->evaluate($object, $asset, $this->createRule(null)));
    }

    #[Test]
    public function returnsTrueWhenConditionIsEmptyString(): void
    {
        $object = $this->createMock(AbstractObject::class);
        $asset = $this->createMock(Asset::class);

        self::assertTrue($this->evaluator->evaluate($object, $asset, $this->createRule('')));
    }

    #[Test]
    public function evaluatesSimpleTrueExpression(): void
    {
        $object = $this->createMock(AbstractObject::class);
        $asset = $this->createMock(Asset::class);

        self::assertTrue($this->evaluator->evaluate($object, $asset, $this->createRule('true')));
    }

    #[Test]
    public function evaluatesSimpleFalseExpression(): void
    {
        $object = $this->createMock(AbstractObject::class);
        $asset = $this->createMock(Asset::class);

        self::assertFalse($this->evaluator->evaluate($object, $asset, $this->createRule('false')));
    }

    #[Test]
    public function returnsFalseOnInvalidExpression(): void
    {
        $object = $this->createMock(AbstractObject::class);
        $asset = $this->createMock(Asset::class);

        self::assertFalse($this->evaluator->evaluate($object, $asset, $this->createRule('invalid_func()')));
    }

    #[Test]
    public function evaluatesAssetTypeFunction(): void
    {
        $object = $this->createMock(AbstractObject::class);
        $asset = $this->createMock(Asset::class);
        $asset->method('getType')->willReturn('image');

        self::assertTrue($this->evaluator->evaluate(
            $object,
            $asset,
            $this->createRule('asset_type(asset) == "image"'),
        ));
    }

    #[Test]
    public function evaluatesAssetSizeFunction(): void
    {
        $object = $this->createMock(AbstractObject::class);
        $asset = $this->createMock(Asset::class);
        $asset->method('getFileSize')->willReturn(5000);

        self::assertTrue($this->evaluator->evaluate(
            $object,
            $asset,
            $this->createRule('asset_size(asset) > 1000'),
        ));
    }

    #[Test]
    public function evaluatesAssetExtensionFunction(): void
    {
        $object = $this->createMock(AbstractObject::class);
        $asset = $this->createMock(Asset::class);
        $asset->method('getFilename')->willReturn('photo.PNG');

        self::assertTrue($this->evaluator->evaluate(
            $object,
            $asset,
            $this->createRule('asset_extension(asset) == "png"'),
        ));
    }

    #[Test]
    public function evaluatesObjectClassFunction(): void
    {
        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $asset = $this->createMock(Asset::class);

        self::assertTrue($this->evaluator->evaluate(
            $object,
            $asset,
            $this->createRule('object_class(object) == "Product"'),
        ));
    }

    #[Test]
    public function evaluatesIsImageFunction(): void
    {
        $object = $this->createMock(AbstractObject::class);
        $asset = $this->createMock(Asset::class);
        $asset->method('getType')->willReturn('image');

        self::assertTrue($this->evaluator->evaluate(
            $object,
            $asset,
            $this->createRule('is_image(asset)'),
        ));
    }

    #[Test]
    public function evaluatesIsVideoFunction(): void
    {
        $object = $this->createMock(AbstractObject::class);
        $asset = $this->createMock(Asset::class);
        $asset->method('getType')->willReturn('video');

        self::assertTrue($this->evaluator->evaluate(
            $object,
            $asset,
            $this->createRule('is_video(asset)'),
        ));
    }

    #[Test]
    public function evaluatesIsDocumentFunction(): void
    {
        $object = $this->createMock(AbstractObject::class);
        $asset = $this->createMock(Asset::class);
        $asset->method('getType')->willReturn('document');

        self::assertTrue($this->evaluator->evaluate(
            $object,
            $asset,
            $this->createRule('is_document(asset)'),
        ));
    }

    #[Test]
    public function isImageReturnsFalseForNonImage(): void
    {
        $object = $this->createMock(AbstractObject::class);
        $asset = $this->createMock(Asset::class);
        $asset->method('getType')->willReturn('document');

        self::assertFalse($this->evaluator->evaluate(
            $object,
            $asset,
            $this->createRule('is_image(asset)'),
        ));
    }

    #[Test]
    public function cachesCompiledExpressions(): void
    {
        $object = $this->createMock(AbstractObject::class);
        $asset = $this->createMock(Asset::class);

        $rule = $this->createRule('true');

        self::assertTrue($this->evaluator->evaluate($object, $asset, $rule));
        self::assertTrue($this->evaluator->evaluate($object, $asset, $rule));
    }

    #[Test]
    public function evaluatesCompoundExpression(): void
    {
        $object = $this->createMock(Concrete::class);
        $object->method('getClassName')->willReturn('Product');
        $asset = $this->createMock(Asset::class);
        $asset->method('getType')->willReturn('image');
        $asset->method('getFileSize')->willReturn(2000);

        self::assertTrue($this->evaluator->evaluate(
            $object,
            $asset,
            $this->createRule('is_image(asset) and asset_size(asset) > 1000 and object_class(object) == "Product"'),
        ));
    }
}
