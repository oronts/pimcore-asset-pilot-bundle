<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\AssetFieldInfo;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\ClassDefinition\Data\Fieldcollections;
use Pimcore\Model\DataObject\ClassDefinition\Data\Localizedfields;
use Pimcore\Model\DataObject\ClassDefinition\Data\Objectbricks;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Data\BlockElement;
use Pimcore\Model\DataObject\Data\ElementMetadata;
use Pimcore\Model\DataObject\Data\Hotspotimage;
use Pimcore\Model\DataObject\Data\ImageGallery;
use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Model\DataObject\Localizedfield;
use Pimcore\Model\DataObject\Objectbrick;
use Pimcore\Tool;
use Psr\Log\LoggerInterface;

class AssetFieldExtractor implements AssetFieldExtractorInterface
{
    protected const array ASSET_FIELD_TYPES = [
        'image',
        'video',
        'document',
        'archive',
        'imageGallery',
        'hotspotimage',
        'manyToOneRelation',
        'manyToManyRelation',
        'advancedManyToManyRelation',
        'advancedManyToOneRelation',
        'manyToManyObjectRelation',
        'block',
    ];

    /** @param string[] $locales locales to scan for localized fields; empty means all valid languages */
    public function __construct(
        protected readonly LoggerInterface $logger,
        protected readonly array $locales = [],
    ) {}

    /** @return AssetFieldInfo[] */
    public function extract(AbstractObject $object): array
    {
        if (!$object instanceof Concrete) {
            $this->logger->debug('AssetFieldExtractor: skipping non-Concrete object {id}', [
                'id' => $object->getId(),
            ]);

            return [];
        }

        $classDef = $object->getClass();
        $locales = $this->resolveLocales();
        $fields = [];

        // The object's own plain + localized asset fields. Object bricks and field collections are
        // structured containers, so their items are traversed separately below.
        $this->collectAssetFields($object, $classDef->getFieldDefinitions(), '', $locales, $fields);
        $this->collectFromBricks($object, $classDef, $locales, $fields);
        $this->collectFromFieldCollections($object, $classDef, $locales, $fields);

        $assetCount = array_sum(array_map(static fn (AssetFieldInfo $f): int => count($f->assets), $fields));

        $this->logger->debug('AssetFieldExtractor: extracted {fieldCount} fields with {assetCount} assets from {class}:{id}', [
            'fieldCount' => count($fields),
            'assetCount' => $assetCount,
            'class' => $classDef->getName(),
            'id' => $object->getId(),
        ]);

        return $fields;
    }

    /** @return Asset[] */
    protected function extractAssetsFromValue(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if ($value instanceof Asset) {
            return [$value];
        }

        if ($value instanceof Hotspotimage) {
            $image = $value->getImage();

            return $image instanceof Asset ? [$image] : [];
        }

        if ($value instanceof ImageGallery) {
            return $this->extractAssetsFromItems($value->getItems());
        }

        if ($value instanceof ElementMetadata) {
            return $this->extractAssetsFromValue($value->getElement());
        }
        if ($value instanceof BlockElement) {
            return $this->extractAssetsFromValue($value->getData());
        }
        if (is_array($value)) {
            return $this->extractAssetsFromItems($value);
        }

        return is_object($value) ? $this->extractAssetsFromObject($value) : [];
    }

    /** @return list<Asset> */
    private function extractAssetsFromItems(iterable $items): array
    {
        $assets = [];
        foreach ($items as $item) {
            array_push($assets, ...$this->extractAssetsFromValue($item));
        }

        return $assets;
    }

    /** @return list<Asset> */
    private function extractAssetsFromObject(object $value): array
    {
        if (method_exists($value, 'getImage')) {
            $image = $value->getImage();

            return $image instanceof Asset ? [$image] : [];
        }
        if (!method_exists($value, 'getItems')) {
            return [];
        }

        $items = $value->getItems();

        return is_iterable($items) ? $this->extractAssetsFromItems($items) : [];
    }

    /**
     * Collect asset fields (plain and localized) from one value holder — the object itself, an
     * object-brick item, or a field-collection item — given that holder's field definitions.
     * $prefix qualifies the reported field name for nested holders (e.g. "myBrick.image"); a rule
     * with an explicit `fields` constraint targets nested fields by that qualified path.
     *
     * @param Data[]           $fieldDefs
     * @param string[]         $locales
     * @param AssetFieldInfo[] $fields
     */
    protected function collectAssetFields(object $holder, array $fieldDefs, string $prefix, array $locales, array &$fields): void
    {
        foreach ($fieldDefs as $fieldDef) {
            if ($fieldDef instanceof Localizedfields) {
                $localized = $this->localizedFieldsOf($holder);
                foreach ($fieldDef->getFieldDefinitions() as $localizedFieldDef) {
                    if (!$this->isAssetField($localizedFieldDef)) {
                        continue;
                    }

                    foreach ($locales as $locale) {
                        $this->addAssetField($holder, $localizedFieldDef, $prefix, $locale, $localized, $fields);
                    }
                }

                continue;
            }

            if ($this->isAssetField($fieldDef)) {
                $this->addAssetField($holder, $fieldDef, $prefix, null, null, $fields);
            }
        }
    }

    /**
     * @param AssetFieldInfo[] $fields
     */
    private function addAssetField(object $holder, Data $fieldDef, string $prefix, ?string $locale, ?Localizedfield $localized, array &$fields): void
    {
        $fieldName = $fieldDef->getName();

        try {
            // ignoreFallbackLanguage=true: only explicitly-set localized values, never one inherited
            // from a parent locale, so a fallback value cannot trigger a duplicate move.
            $value = $locale !== null
                ? $localized?->getLocalizedValue($fieldName, $locale, true)
                : $this->readField($holder, $fieldName);

            $assets = $this->extractAssetsFromValue($value);
            if ($assets !== []) {
                $fields[] = new AssetFieldInfo(
                    fieldName: $prefix . $fieldName,
                    locale: $locale,
                    fieldType: $fieldDef->getFieldType(),
                    assets: $assets,
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning('AssetFieldExtractor: failed to read field {field} on {holder}: {error}', [
                'field' => $prefix . $fieldName . ($locale !== null ? '/' . $locale : ''),
                'holder' => $holder::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param string[]         $locales
     * @param AssetFieldInfo[] $fields
     */
    protected function collectFromBricks(Concrete $object, ClassDefinition $classDef, array $locales, array &$fields): void
    {
        foreach ($classDef->getFieldDefinitions() as $fieldDef) {
            if (!$fieldDef instanceof Objectbricks) {
                continue;
            }

            try {
                $container = $this->readField($object, $fieldDef->getName());
                if (!$container instanceof Objectbrick) {
                    continue;
                }

                foreach ($container->getItems() as $brick) {
                    if (!$brick instanceof Objectbrick\Data\AbstractData) {
                        continue;
                    }

                    $brickDef = $this->objectbrickDefinition($brick->getType());
                    if ($brickDef === null) {
                        continue;
                    }

                    $this->collectAssetFields($brick, $brickDef->getFieldDefinitions(), $fieldDef->getName() . '.', $locales, $fields);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('AssetFieldExtractor: failed to read object-brick {field} on object {id}: {error}', [
                    'field' => $fieldDef->getName(),
                    'id' => $object->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param string[]         $locales
     * @param AssetFieldInfo[] $fields
     */
    protected function collectFromFieldCollections(Concrete $object, ClassDefinition $classDef, array $locales, array &$fields): void
    {
        foreach ($classDef->getFieldDefinitions() as $fieldDef) {
            if (!$fieldDef instanceof Fieldcollections) {
                continue;
            }

            try {
                $container = $this->readField($object, $fieldDef->getName());
                if (!$container instanceof Fieldcollection) {
                    continue;
                }

                foreach ($container->getItems() as $item) {
                    if (!$item instanceof Fieldcollection\Data\AbstractData) {
                        continue;
                    }

                    $itemDef = $this->fieldcollectionDefinition($item->getType());
                    if ($itemDef === null) {
                        continue;
                    }

                    $this->collectAssetFields($item, $itemDef->getFieldDefinitions(), $fieldDef->getName() . '.', $locales, $fields);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('AssetFieldExtractor: failed to read field-collection {field} on object {id}: {error}', [
                    'field' => $fieldDef->getName(),
                    'id' => $object->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function readField(object $holder, string $fieldName): mixed
    {
        if (method_exists($holder, 'getValueForFieldName')) {
            return $holder->getValueForFieldName($fieldName);
        }

        $getter = 'get' . ucfirst($fieldName);

        return method_exists($holder, $getter) ? $holder->$getter() : null;
    }

    protected function localizedFieldsOf(object $holder): ?Localizedfield
    {
        if (!method_exists($holder, 'getLocalizedfields')) {
            return null;
        }

        $localized = $holder->getLocalizedfields();

        return $localized instanceof Localizedfield ? $localized : null;
    }

    protected function objectbrickDefinition(string $key): ?Objectbrick\Definition
    {
        return Objectbrick\Definition::getByKey($key);
    }

    protected function fieldcollectionDefinition(string $key): ?Fieldcollection\Definition
    {
        return Fieldcollection\Definition::getByKey($key);
    }

    /** @return string[] */
    protected function resolveLocales(): array
    {
        $valid = $this->validLanguages();
        if ($this->locales === []) {
            return $valid;
        }

        return array_values(array_intersect($this->locales, $valid));
    }

    /** @return string[] */
    protected function validLanguages(): array
    {
        return Tool::getValidLanguages();
    }

    protected function isAssetField(Data $fieldDef): bool
    {
        // Relation field types are included because they may carry assets; extract() filters the
        // actual referenced assets at runtime, so object-only relations simply yield none.
        return in_array($fieldDef->getFieldType(), self::ASSET_FIELD_TYPES, true);
    }
}
