<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Service\AssetFieldExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Data\ElementMetadata;
use Pimcore\Model\DataObject\Data\Hotspotimage;
use Psr\Log\NullLogger;

#[CoversClass(AssetFieldExtractor::class)]
class AssetFieldExtractorTest extends TestCase
{
    private object $extractor;

    protected function setUp(): void
    {
        $this->extractor = new class (new NullLogger()) extends AssetFieldExtractor {
            public function extractFrom(mixed $value): array
            {
                return $this->extractAssetsFromValue($value);
            }

            /**
             * @param \Pimcore\Model\DataObject\ClassDefinition\Data[] $fieldDefs
             * @param string[]                                         $locales
             * @return \Oronts\AssetPilotBundle\Model\AssetFieldInfo[]
             */
            public function collect(object $holder, array $fieldDefs, string $prefix, array $locales): array
            {
                $fields = [];
                $this->collectAssetFields($holder, $fieldDefs, $prefix, $locales, $fields);

                return $fields;
            }

            public function read(object $holder, string $fieldName): mixed
            {
                return $this->readField($holder, $fieldName);
            }
        };
    }

    #[Test]
    public function unwrapsAdvancedRelationElementMetadataToTheAsset(): void
    {
        $asset = $this->createMock(Asset::class);
        $meta = $this->createMock(ElementMetadata::class);
        $meta->method('getElement')->willReturn($asset);

        // advancedMany*Relation returns an array of ElementMetadata.
        self::assertSame([$asset], $this->extractor->extractFrom([$meta]));
    }

    #[Test]
    public function elementMetadataWrappingANonAssetYieldsNothing(): void
    {
        $meta = $this->createMock(ElementMetadata::class);
        $meta->method('getElement')->willReturn(null);

        self::assertSame([], $this->extractor->extractFrom($meta));
    }

    #[Test]
    public function extractsADirectAsset(): void
    {
        $asset = $this->createMock(Asset::class);

        self::assertSame([$asset], $this->extractor->extractFrom($asset));
    }

    #[Test]
    public function extractsTheImageOutOfAHotspotImage(): void
    {
        $image = $this->createMock(Asset\Image::class);
        $hotspot = $this->createMock(Hotspotimage::class);
        $hotspot->method('getImage')->willReturn($image);

        self::assertSame([$image], $this->extractor->extractFrom($hotspot));
    }

    #[Test]
    public function nullYieldsNothing(): void
    {
        self::assertSame([], $this->extractor->extractFrom(null));
    }

    #[Test]
    public function readFieldPrefersGetValueForFieldName(): void
    {
        $asset = $this->createMock(Asset::class);
        $holder = $this->createMock(Concrete::class);
        $holder->method('getValueForFieldName')->with('image')->willReturn($asset);

        self::assertSame($asset, $this->extractor->read($holder, 'image'));
    }

    #[Test]
    public function readFieldFallsBackToTheGetterWhenNoValueForFieldName(): void
    {
        $holder = new class () {
            public function getCover(): string
            {
                return 'cover-value';
            }
        };

        self::assertSame('cover-value', $this->extractor->read($holder, 'cover'));
    }

    #[Test]
    public function readFieldReturnsNullWhenNeitherAccessorExists(): void
    {
        self::assertNull($this->extractor->read(new \stdClass(), 'whatever'));
    }

    #[Test]
    public function collectQualifiesNestedAssetFieldNamesWithThePrefix(): void
    {
        $asset = $this->createMock(Asset::class);
        $holder = $this->createMock(Concrete::class);
        $holder->method('getValueForFieldName')->with('image')->willReturn($asset);

        $fields = $this->extractor->collect($holder, [$this->assetFieldDef('image', 'image')], 'productBrick.', ['en']);

        self::assertCount(1, $fields);
        self::assertSame('productBrick.image', $fields[0]->fieldName);
        self::assertNull($fields[0]->locale);
        self::assertSame('image', $fields[0]->fieldType);
        self::assertSame([$asset], $fields[0]->assets);
    }

    #[Test]
    public function collectSkipsNonAssetFields(): void
    {
        $holder = $this->createMock(Concrete::class);
        $holder->method('getValueForFieldName')->willReturn('some text');

        $fields = $this->extractor->collect($holder, [$this->assetFieldDef('name', 'input')], '', ['en']);

        self::assertSame([], $fields);
    }

    private function assetFieldDef(string $name, string $fieldType): \Pimcore\Model\DataObject\ClassDefinition\Data
    {
        $def = $this->createMock(\Pimcore\Model\DataObject\ClassDefinition\Data::class);
        $def->method('getName')->willReturn($name);
        $def->method('getFieldType')->willReturn($fieldType);

        return $def;
    }

    #[Test]
    public function emptyConfiguredLocalesScansAllValidLanguages(): void
    {
        self::assertSame(['en', 'de'], $this->localeResolver([], ['en', 'de'])->locales());
    }

    #[Test]
    public function configuredLocalesAreIntersectedWithValidLanguages(): void
    {
        self::assertSame(['de', 'fr'], $this->localeResolver(['de', 'fr', 'xx'], ['en', 'de', 'fr'])->locales());
    }

    /**
     * @param string[] $configured
     * @param string[] $valid
     */
    private function localeResolver(array $configured, array $valid): object
    {
        return new class (new NullLogger(), $configured, $valid) extends AssetFieldExtractor {
            /**
             * @param string[] $configured
             * @param string[] $valid
             */
            public function __construct(NullLogger $logger, array $configured, private readonly array $valid)
            {
                parent::__construct($logger, $configured);
            }

            protected function validLanguages(): array
            {
                return $this->valid;
            }

            /** @return string[] */
            public function locales(): array
            {
                return $this->resolveLocales();
            }
        };
    }
}
