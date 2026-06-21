<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Action;

use Oronts\AssetPilotBundle\Enum\PropertyType;
use Oronts\AssetPilotBundle\Service\AssetPropertyService;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

/**
 * Sets an asset property from a static `value` or, for object-derived metadata (F18), from the
 * owning object via a `from` getter. Writes through AssetPropertyService (a direct properties-table
 * write, so it does not save the asset and cannot re-enter the organize pipeline).
 */
class SetPropertyAction implements RuleActionInterface
{
    public function __construct(
        protected readonly AssetPropertyService $propertyService,
    ) {}

    public function getType(): string
    {
        return 'set_property';
    }

    public function apply(Asset $asset, AbstractObject $object, array $config): void
    {
        $name = (string) ($config['name'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('The set_property action requires a "name".');
        }

        $type = (string) ($config['property_type'] ?? PropertyType::Text->value);
        $value = array_key_exists('from', $config)
            ? $this->deriveFromObject($object, (string) $config['from'])
            : $this->castValue($config['value'] ?? '');

        $this->propertyService->setProperty($asset->getId(), $asset->getRealFullPath(), $name, $type, $value);
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
