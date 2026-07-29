<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Action;

use Oronts\AssetPilotBundle\Enum\PropertyType;
use Oronts\AssetPilotBundle\Service\AssetPropertyServiceInterface;
use Oronts\AssetPilotBundle\Support\PropertyValue;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\Property;

/**
 * Sets an asset property from a static `value` or, for object-derived metadata, from the
 * owning object via a `from` getter. The active move already owns the asset lock, so the property
 * service uses its locked-asset native Pimcore save path without reacquiring and releasing that lock.
 */
class SetPropertyAction implements RuleActionInterface, RuleActionConfigValidatorInterface
{
    public function __construct(
        protected readonly AssetPropertyServiceInterface $propertyService,
    ) {}

    public function getType(): string
    {
        return 'set_property';
    }

    public function prepare(Asset $asset, AbstractObject $object, array $config): array
    {
        unset($asset);
        $name = (string) ($config['name'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('The set_property action requires a "name".');
        }

        $type = (string) ($config['property_type'] ?? PropertyType::Text->value);
        $value = array_key_exists('from', $config)
            ? $this->deriveFromObject($object, (string) $config['from'])
            : $this->castValue($config['value'] ?? '');

        return ['name' => $name, 'type' => $type, 'value' => $value];
    }

    public function applyPrepared(Asset $asset, array $payload, RuleActionDeliveryContextInterface $delivery): void
    {
        unset($delivery);
        $name = (string) ($payload['name'] ?? '');
        $type = (string) ($payload['type'] ?? '');
        $value = (string) ($payload['value'] ?? '');
        if ($name === '' || !in_array($type, PropertyType::values(), true)) {
            throw new \InvalidArgumentException('The prepared set_property payload is invalid.');
        }

        $property = $asset->getProperty($name, true);
        $expectedValue = $type === PropertyType::Bool->value ? PropertyValue::normalize(PropertyType::Bool, $value) : $value;
        if ($property instanceof Property && $property->getType() === $type && $property->getData() === $expectedValue) {
            return;
        }

        $this->propertyService->setPropertyOnLockedAsset($asset, $name, $type, $value);
    }

    public function validateConfig(array $config): array
    {
        $errors = [];
        if (trim((string) ($config['name'] ?? '')) === '') {
            $errors[] = 'requires a non-empty "name"';
        }
        $type = (string) ($config['property_type'] ?? PropertyType::Text->value);
        if (!in_array($type, PropertyType::values(), true)) {
            $errors[] = 'property_type must be one of: ' . implode(', ', PropertyType::values());
        }
        if (!array_key_exists('value', $config) && trim((string) ($config['from'] ?? '')) === '') {
            $errors[] = 'requires either "value" or a non-empty "from"';
        }
        if ($type === PropertyType::Bool->value && array_key_exists('value', $config)) {
            if (!is_scalar($config['value'])) {
                $errors[] = 'value must be a boolean for property_type "bool"';
            } else {
                try {
                    PropertyValue::normalize(PropertyType::Bool, $config['value']);
                } catch (\InvalidArgumentException) {
                    $errors[] = 'value must be a boolean (true/false/1/0/yes/no/on/off) for property_type "bool"';
                }
            }
        }

        return $errors;
    }

    /**
     * Read a value from the owning object via a get/is/has accessor (read-only, never an arbitrary
     * method), coerced to a property string.
     */
    protected function deriveFromObject(object $object, string $property): string
    {
        foreach (['get', 'is', 'has'] as $prefix) {
            $getter = $prefix . ucfirst($property);
            if (method_exists($object, $getter)) {
                return $this->castValue($object->$getter());
            }
        }

        return '';
    }

    private function castValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
