<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\AssetFieldInfo;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\ClassDefinition\Data\Localizedfields;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Data\ElementMetadata;
use Pimcore\Model\DataObject\Data\Hotspotimage;
use Pimcore\Model\DataObject\Data\ImageGallery;
use Pimcore\Tool;
use Psr\Log\LoggerInterface;

class AssetFieldExtractor
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
    ];

    public function __construct(
        protected readonly LoggerInterface $logger,
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
        $fields = [];

        foreach ($this->getAssetFields($classDef) as $fieldDef) {
            $fieldName = $fieldDef->getName();

            try {
                $value = $object->getValueForFieldName($fieldName);
                $assets = $this->extractAssetsFromValue($value);

                if ($assets !== []) {
                    $fields[] = new AssetFieldInfo(
                        fieldName: $fieldName,
                        locale: null,
                        fieldType: $fieldDef->getFieldType(),
                        assets: $assets,
                    );
                }
            } catch (\Throwable $e) {
                $this->logger->warning('AssetFieldExtractor: failed to read field {field} on object {id}: {error}', [
                    'field' => $fieldName,
                    'id' => $object->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $localizedFields = $object->getLocalizedFields();
        foreach ($this->getLocalizedAssetFields($classDef) as $fieldDef) {
            $fieldName = $fieldDef->getName();

            foreach (Tool::getValidLanguages() as $locale) {
                try {
                    // Use ignoreFallbackLanguage=true to only get explicitly set values,
                    // not inherited from parent locales (prevents duplicate moves)
                    $value = $localizedFields?->getLocalizedValue($fieldName, $locale, true);
                    $assets = $this->extractAssetsFromValue($value);

                    if ($assets !== []) {
                        $fields[] = new AssetFieldInfo(
                            fieldName: $fieldName,
                            locale: $locale,
                            fieldType: $fieldDef->getFieldType(),
                            assets: $assets,
                        );
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('AssetFieldExtractor: failed to read localized field {field}/{locale} on object {id}: {error}', [
                        'field' => $fieldName,
                        'locale' => $locale,
                        'id' => $object->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

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
            $assets = [];

            foreach ($value->getItems() as $item) {
                if ($item instanceof Hotspotimage) {
                    $image = $item->getImage();

                    if ($image instanceof Asset) {
                        $assets[] = $image;
                    }
                }
            }

            return $assets;
        }

        // advancedMany*Relation fields wrap each target in ElementMetadata; unwrap to the element.
        if ($value instanceof ElementMetadata) {
            return $this->extractAssetsFromValue($value->getElement());
        }

        if (is_array($value)) {
            $assets = [];

            foreach ($value as $item) {
                $assets = [...$assets, ...$this->extractAssetsFromValue($item)];
            }

            return $assets;
        }

        if (is_object($value)) {
            if (method_exists($value, 'getImage')) {
                $image = $value->getImage();

                return $image instanceof Asset ? [$image] : [];
            }

            if (method_exists($value, 'getItems')) {
                $assets = [];

                foreach ($value->getItems() as $item) {
                    $assets = [...$assets, ...$this->extractAssetsFromValue($item)];
                }

                return $assets;
            }
        }

        return [];
    }

    /** @return Data[] */
    protected function getAssetFields(ClassDefinition $classDef): array
    {
        $result = [];

        foreach ($classDef->getFieldDefinitions() as $fieldDef) {
            if ($fieldDef instanceof Localizedfields) {
                continue;
            }

            if ($this->isAssetField($fieldDef)) {
                $result[] = $fieldDef;
            }
        }

        return $result;
    }

    /** @return Data[] */
    protected function getLocalizedAssetFields(ClassDefinition $classDef): array
    {
        $result = [];

        foreach ($classDef->getFieldDefinitions() as $fieldDef) {
            if (!$fieldDef instanceof Localizedfields) {
                continue;
            }

            foreach ($fieldDef->getFieldDefinitions() as $localizedFieldDef) {
                if ($this->isAssetField($localizedFieldDef)) {
                    $result[] = $localizedFieldDef;
                }
            }
        }

        return $result;
    }

    protected function isAssetField(Data $fieldDef): bool
    {
        $fieldType = $fieldDef->getFieldType();

        if (in_array($fieldType, self::ASSET_FIELD_TYPES, true)) {
            // For relation fields, check if they allow Asset types
            if (str_contains($fieldType, 'Relation') || str_contains($fieldType, 'relation')) {
                if (method_exists($fieldDef, 'getAssetTypes')) {
                    return true;
                }

                if (method_exists($fieldDef, 'getDocumentTypes')) {
                    return true;
                }
            }

            return true;
        }

        return false;
    }
}
