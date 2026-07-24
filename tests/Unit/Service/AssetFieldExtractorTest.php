<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Service\AssetFieldExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\ClassDefinition\Data\Classificationstore as ClassificationstoreDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data\Fieldcollections;
use Pimcore\Model\DataObject\Classificationstore as ClassificationstoreValue;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Data\BlockElement;
use Pimcore\Model\DataObject\Data\ElementMetadata;
use Pimcore\Model\DataObject\Data\Hotspotimage;
use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Model\DataObject\Fieldcollection\Data\AbstractData;
use Pimcore\Model\DataObject\Fieldcollection\Definition;
use Pimcore\Model\DataObject\Localizedfield;
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

            public function localizedFields(object $holder): ?Localizedfield
            {
                return $this->localizedFieldsOf($holder);
            }
        };
    }

    #[Test]
    public function classificationStoreAssetIdsFailsClosedOnAnUnrecognizedAssetValueShape(): void
    {
        $imageKey = $this->createMock(Data::class);
        $imageKey->method('getFieldType')->willReturn('image');

        $extractor = new class (new NullLogger()) extends AssetFieldExtractor {
            /** @var array<int, Data> */
            public array $keyDefinitions = [];
            /** @var array<string, Data> */
            public array $fieldDefinitions = [];
            public mixed $storeValue = null;

            protected function classFieldDefinitions(Concrete $object): array
            {
                return $this->fieldDefinitions;
            }

            protected function readField(object $holder, string $fieldName): mixed
            {
                return $this->storeValue;
            }

            protected function classificationKeyDefinition(int $keyId): ?Data
            {
                return $this->keyDefinitions[$keyId] ?? null;
            }
        };
        $extractor->keyDefinitions = [10 => $imageKey, 20 => $imageKey];

        $store = $this->createMock(ClassificationstoreValue::class);
        // key 10 (asset field) is an empty value, which is a recognized "no asset" form; key 20 holds a
        // non-numeric string, which is NOT a recognized asset reference and must fail the traversal closed.
        $store->method('getItems')->willReturn([1 => [10 => ['default' => null], 20 => ['default' => 'not-an-asset-id']]]);
        $extractor->storeValue = $store;

        $csField = $this->createMock(ClassificationstoreDefinition::class);
        $csField->method('getName')->willReturn('cs');
        $extractor->fieldDefinitions = ['cs' => $csField];

        $extraction = $extractor->classificationStoreAssetIds($this->createMock(Concrete::class));
        self::assertSame([], $extraction->targetIds);
        self::assertFalse($extraction->complete, 'an unrecognized asset-field value shape fails closed');
    }

    #[Test]
    public function classificationStoreAssetIdsExtractsAssetKeyValuesAndSkipsNonAssetKeys(): void
    {
        $imageKey = $this->createMock(Data::class);
        $imageKey->method('getFieldType')->willReturn('image');
        $inputKey = $this->createMock(Data::class);
        $inputKey->method('getFieldType')->willReturn('input');

        $extractor = new class (new NullLogger()) extends AssetFieldExtractor {
            /** @var array<int, Data> */
            public array $keyDefinitions = [];
            /** @var array<string, Data> */
            public array $fieldDefinitions = [];
            public mixed $storeValue = null;

            protected function classFieldDefinitions(Concrete $object): array
            {
                return $this->fieldDefinitions;
            }

            protected function readField(object $holder, string $fieldName): mixed
            {
                return $this->storeValue;
            }

            protected function classificationKeyDefinition(int $keyId): ?Data
            {
                return $this->keyDefinitions[$keyId] ?? null;
            }
        };
        $extractor->keyDefinitions = [10 => $imageKey, 20 => $inputKey];

        $store = $this->createMock(ClassificationstoreValue::class);
        // group 1: key 10 (image) holds asset id 42; key 20 (input) holds 99, which is not an asset reference.
        $store->method('getItems')->willReturn([1 => [10 => ['default' => 42], 20 => ['default' => 99]]]);
        $extractor->storeValue = $store;

        $csField = $this->createMock(ClassificationstoreDefinition::class);
        $csField->method('getName')->willReturn('cs');
        $extractor->fieldDefinitions = ['cs' => $csField];

        $extraction = $extractor->classificationStoreAssetIds($this->createMock(Concrete::class));
        self::assertSame([42], $extraction->targetIds);
        self::assertTrue($extraction->complete, 'every key resolved, so the traversal is complete');
    }

    #[Test]
    public function classificationStoreAssetIdsFailsClosedWhenAKeyCannotBeResolved(): void
    {
        $imageKey = $this->createMock(Data::class);
        $imageKey->method('getFieldType')->willReturn('image');

        $extractor = new class (new NullLogger()) extends AssetFieldExtractor {
            /** @var array<int, Data> */
            public array $keyDefinitions = [];
            /** @var array<string, Data> */
            public array $fieldDefinitions = [];
            public mixed $storeValue = null;

            protected function classFieldDefinitions(Concrete $object): array
            {
                return $this->fieldDefinitions;
            }

            protected function readField(object $holder, string $fieldName): mixed
            {
                return $this->storeValue;
            }

            protected function classificationKeyDefinition(int $keyId): ?Data
            {
                return $this->keyDefinitions[$keyId] ?? null;
            }
        };
        $extractor->keyDefinitions = [10 => $imageKey];

        $store = $this->createMock(ClassificationstoreValue::class);
        // key 10 resolves to asset 42; key 99 has no resolvable definition, so it might itself be an asset ref.
        $store->method('getItems')->willReturn([1 => [10 => ['default' => 42], 99 => ['default' => 500]]]);
        $extractor->storeValue = $store;

        $csField = $this->createMock(ClassificationstoreDefinition::class);
        $csField->method('getName')->willReturn('cs');
        $extractor->fieldDefinitions = ['cs' => $csField];

        $extraction = $extractor->classificationStoreAssetIds($this->createMock(Concrete::class));
        self::assertSame([42], $extraction->targetIds, 'the resolved ids are still returned as a lower bound');
        self::assertFalse($extraction->complete, 'an unresolvable key marks the traversal incomplete so callers fail closed');
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
    public function unwrapsAssetsNestedInBlockElements(): void
    {
        $asset = $this->createMock(Asset::class);
        $imageElement = $this->createMock(BlockElement::class);
        $imageElement->method('getData')->willReturn($asset);
        $textElement = $this->createMock(BlockElement::class);
        $textElement->method('getData')->willReturn('a title');

        // A block value is rows of [subFieldName => BlockElement].
        $blockValue = [['image' => $imageElement, 'title' => $textElement]];

        self::assertSame([$asset], $this->extractor->extractFrom($blockValue));
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
    public function localizedFieldsUsesTheGeneratedPimcoreAccessor(): void
    {
        $localizedFields = new Localizedfield();
        $holder = new class ($localizedFields) {
            public function __construct(private readonly Localizedfield $localizedFields) {}

            public function getLocalizedfields(): Localizedfield
            {
                return $this->localizedFields;
            }
        };

        self::assertSame($localizedFields, $this->extractor->localizedFields($holder));
        self::assertNull($this->extractor->localizedFields(new \stdClass()));
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

    #[Test]
    public function collectDoesNotTreatContainerFieldTypesAsAssets(): void
    {
        $holder = $this->createMock(Concrete::class);

        $fields = $this->extractor->collect($holder, [
            $this->assetFieldDef('myBricks', 'objectbricks'),
            $this->assetFieldDef('myItems', 'fieldcollections'),
        ], '', ['en']);

        self::assertSame([], $fields);
    }

    #[Test]
    public function extractsAssetsNestedInAFieldCollectionWithAQualifiedName(): void
    {
        $asset = $this->createMock(Asset::class);

        $item = new class () extends AbstractData {
            public ?Asset $cover = null;

            public function getType(): string
            {
                return 'TestFc';
            }

            public function getCover(): ?Asset
            {
                return $this->cover;
            }
        };
        $item->cover = $asset;

        $fc = new Fieldcollection([$item], 'specs');

        $object = $this->createMock(Concrete::class);
        $object->method('getValueForFieldName')->with('specs')->willReturn($fc);
        $object->method('getClass')->willReturn($this->classDefWith($this->fcContainerFieldDef('specs')));

        $fcDef = $this->createMock(Definition::class);
        $fcDef->method('getFieldDefinitions')->willReturn([$this->assetFieldDef('cover', 'image')]);

        $extractor = new class (new NullLogger(), $fcDef) extends AssetFieldExtractor {
            public function __construct(NullLogger $logger, private readonly Definition $fcDef)
            {
                parent::__construct($logger);
            }

            protected function validLanguages(): array
            {
                return ['en'];
            }

            protected function fieldcollectionDefinition(string $key): Definition
            {
                return $this->fcDef;
            }
        };

        $infos = $extractor->extract($object);

        self::assertCount(1, $infos);
        self::assertSame('specs.cover', $infos[0]->fieldName);
        self::assertSame([$asset], $infos[0]->assets);
    }

    #[Test]
    public function fieldCollectionWithAnUnknownDefinitionDegradesToNothing(): void
    {
        $item = new class () extends AbstractData {
            public function getType(): string
            {
                return 'Unknown';
            }
        };

        $fc = new Fieldcollection([$item], 'specs');
        $object = $this->createMock(Concrete::class);
        $object->method('getValueForFieldName')->with('specs')->willReturn($fc);
        $object->method('getClass')->willReturn($this->classDefWith($this->fcContainerFieldDef('specs')));

        $extractor = new class (new NullLogger()) extends AssetFieldExtractor {
            protected function validLanguages(): array
            {
                return ['en'];
            }

            protected function fieldcollectionDefinition(string $key): ?Definition
            {
                return null;
            }
        };

        self::assertSame([], $extractor->extract($object));
    }

    private function classDefWith(Data $fieldDef): \Pimcore\Model\DataObject\ClassDefinition
    {
        $classDef = new \Pimcore\Model\DataObject\ClassDefinition();
        $classDef->setName('TestClass');
        $classDef->setFieldDefinitions([$fieldDef->getName() => $fieldDef]);

        return $classDef;
    }

    private function assetFieldDef(string $name, string $fieldType): \Pimcore\Model\DataObject\ClassDefinition\Data
    {
        $def = $this->createMock(\Pimcore\Model\DataObject\ClassDefinition\Data::class);
        $def->method('getName')->willReturn($name);
        $def->method('getFieldType')->willReturn($fieldType);

        return $def;
    }

    private function fcContainerFieldDef(string $name): Fieldcollections
    {
        $def = $this->createMock(Fieldcollections::class);
        $def->method('getName')->willReturn($name);

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
