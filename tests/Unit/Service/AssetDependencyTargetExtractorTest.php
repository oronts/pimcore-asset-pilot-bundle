<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Model\DependencyExtraction;
use Oronts\AssetPilotBundle\Service\AssetDependencyTargetExtractor;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

#[CoversClass(AssetDependencyTargetExtractor::class)]
class AssetDependencyTargetExtractorTest extends TestCase
{
    #[Test]
    public function extractsDistinctNumericallySortedAssetIdsIgnoringNonAssets(): void
    {
        $source = $this->createMock(AbstractObject::class);
        $source->method('getId')->willReturn(500);
        $source->method('resolveDependencies')->willReturn([
            ['id' => 30, 'type' => 'asset'],
            ['id' => 5, 'type' => 'asset'],
            ['id' => 30, 'type' => 'asset'],
            ['id' => 9, 'type' => 'object'],
            ['id' => 200, 'type' => 'asset'],
            ['id' => 0, 'type' => 'asset'],
            ['type' => 'asset'],
        ]);

        $extraction = $this->extractor()->extract($source);
        self::assertSame([5, 30, 200], $extraction->targetIds);
        self::assertTrue($extraction->complete);
    }

    #[Test]
    public function excludesAnAssetsOwnSelfReference(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(77);
        $asset->method('resolveDependencies')->willReturn([
            ['id' => 77, 'type' => 'asset'],
            ['id' => 88, 'type' => 'asset'],
        ]);

        self::assertSame([88], $this->extractor()->extract($asset)->targetIds);
    }

    #[Test]
    public function returnsAnEmptyListWhenNoAssetDependenciesExist(): void
    {
        $source = $this->createMock(AbstractObject::class);
        $source->method('getId')->willReturn(1);
        $source->method('resolveDependencies')->willReturn([
            ['id' => 3, 'type' => 'object'],
            ['id' => 4, 'type' => 'document'],
        ]);

        self::assertSame([], $this->extractor()->extract($source)->targetIds);
    }

    #[Test]
    public function mergesClassificationStoreTargetsAndPropagatesIncompleteness(): void
    {
        $source = $this->createMock(AbstractObject::class);
        $source->method('getId')->willReturn(9);
        $source->method('resolveDependencies')->willReturn([['id' => 12, 'type' => 'asset']]);

        // A classification-store read that could not be fully resolved must mark the whole extraction incomplete,
        // so a caller (projection/fence) fails closed rather than deleting an asset held only in that store.
        $extraction = $this->extractor(new DependencyExtraction([7], false))->extract($source);

        self::assertSame([7, 12], $extraction->targetIds);
        self::assertFalse($extraction->complete);
    }

    private function extractor(?DependencyExtraction $classification = null): AssetDependencyTargetExtractor
    {
        $fieldExtractor = $this->createStub(AssetFieldExtractorInterface::class);
        $fieldExtractor->method('classificationStoreAssetIds')->willReturn($classification ?? new DependencyExtraction([], true));

        return new AssetDependencyTargetExtractor($fieldExtractor);
    }
}
